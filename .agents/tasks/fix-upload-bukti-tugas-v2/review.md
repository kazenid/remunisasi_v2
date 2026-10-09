# Clear oversize-upload message and single submit toast for bukti tugas

The change fixes two defects in the Katimker task-evidence upload flow. Bug 1: an oversized `foto_selfie` (or `bukti`) previously surfaced Laravel's generic English "The foto_selfie.0 failed to upload.", with no size named. Bug 2: submitting a task with no selfie fired both an error and a success toast. The fix adds Indonesian custom validation messages (including the implicit `uploaded` rule and a `PostTooLargeException` handler) plus a client-side size guard for Bug 1, and switches submit/delete toasts from Inertia's `onSuccess` callback to server flash as the single source of truth for Bug 2. The size number (5 MB selfie / 10 MB bukti) is derived from the backend rules and threaded through messages and client checks consistently.

Watch for: identical consecutive failure messages won't re-fire the flash watcher in `TaskList.vue` (second repeat shows no toast) — minor, non-blocking (likely). The `post_max_size` branch depends on `php.ini` and is only manually verifiable (acknowledged in evidence) (confirmed).

**Verdict**: APPROVED

## High-level view

Bug 1's size number has a single origin: the backend `max:5120`/`max:10240` rules (5/10 MB). The custom messages in `TaskDraftRequest::messages()`, the frontend constants `MAKS_SELFIE_MB`/`MAKS_BUKTI_MB`, and the `PostTooLargeException` text all state those same numbers, so there is no drift. Three layers cover the failure: a client-side guard that rejects before submit (primary), custom `max`/`uploaded` validation messages as the backstop for both the app-rule and `UPLOAD_ERR_INI_SIZE` cases, and the `PostTooLargeException` handler for the `post_max_size` case that never reaches validation.

Bug 2 is fixed by removing the premise that made the double toast possible. A failed submit returns `back()->with('error', ...)` — a 302→200 that Inertia treats as success, so the old `onSuccess` toast fired alongside the flash-error watcher. Toasts are now driven solely by server flash (`flash.success` / `flash.error`), and the controller never attaches a success flash to a failed submit, so failure yields exactly one error toast and success yields exactly one success toast. The server contract (`assertRedirect` + `assertSessionHas('error')`) is untouched; the fix lives entirely in the frontend, keeping blast radius small.

`TaskForm.vue` applies the same single-source principle differently: its save path uses `withErrors(...)` (a real Inertia validation error → `onError`), so the stale `immediate: true` flash-error watcher was removed to avoid both a double toast and a stale toast on form open; errors now flow only through `onError`.

<details>
<summary>Issues (2)</summary>

1. **Repeated identical failure message** — `watch(() => page.props.flash?.error)` compares by value, so a second identical failure in a row (same error string) won't re-trigger the toast. Non-blocking; acceptable given Bug 2 is the priority. Could be addressed later by keying off a nonce or clearing the flash after display.
2. **`post_max_size` path manually-verified only** — the `PostTooLargeException` handler can't be reproduced in the test suite without environment config; evidence correctly flags it as needs-manual-verification. No action required for approval; worth a one-time manual check with a small `post_max_size`.

</details>

<details>
<summary>Details</summary>

### Size number consistency across the three layers

The authoritative numbers are the unchanged rules in `TaskDraftRequest::rules()`: `foto_selfie.* max:5120` (5 MB) and `bukti.*`/`dokumen.* max:10240` (10 MB). Every user-facing number traces back to these:

- `messages()`: `foto_selfie.*.max` → "Ukuran foto selfie maksimal 5 MB.", `foto_selfie.*.uploaded` → "...Ukuran foto selfie maksimal 5 MB.", `bukti.*.max` → "...10 MB per berkas.", `bukti.*.uploaded`/`dokumen.*.uploaded` → matching 10 MB text.
- Frontend constants `MAKS_SELFIE_MB = 5` / `MAKS_BUKTI_MB = 10` feed the SelfieCapture `:maxSizeMb`, the FileUpload `:maxFileSize="MAKS_BUKTI_MB * 1024 * 1024"`, `:invalidFileSizeMessage`, the hint text, and all three toast strings.

There is no hardcoded divergent number left: the previous literal `:maxFileSize="10485760"` was replaced with the computed constant. The `uploaded` message is the right mechanism for the `UPLOAD_ERR_INI_SIZE` case — that rule is implicit on every `file` field and fires when PHP rejects the upload before `max` can run, which is exactly the path that produced the old generic text. (confirmed)

### Bug 2: why one toast now, and why the server contract is intact

```
failed submit:  TaskController@submit  --catch-->  back()->with('error', ...)   [no success flash]
                                                      │
                                 Inertia visit (302→200, "success")
                                                      │
                 TaskList watchers:  flash.error = msg  → 1 error toast
                                     flash.success = null → no toast
```

The controller was read directly: `submit()` on `TaskSubmissionException` returns `back()->with('error', $e->getMessage())` with no success flash, and the success path returns `redirect()->...->with('success', 'Tugas berhasil diajukan.')`. `HandleInertiaRequests` shares `flash.success`/`flash.error` as one-time `session()->get(...)` values. Removing the `onSuccess` toast from `konfirmasiAjukan` and `konfirmasiHapus` and driving both from the flash watchers means failure → error flash only → one toast; success → success flash only → one toast. The `KatimkerSubmitTest` contract (`assertRedirect` + `assertSessionHas('error')`) is preserved, and the new `assertSessionMissing('success')` assertion is the server-side proof that a failed submit can never carry a success flash. (confirmed)

Edge case: the watchers compare the watched value by identity. Two consecutive submits that fail with the identical error string will leave `flash.error` equal across both responses, so the second won't re-fire. This is a pre-existing characteristic of value-compare flash watchers rather than something this change introduces, and it only affects a repeat of the exact same failure. Not blocking. (likely)

### Bug 2 in TaskForm: a deliberately different single-source choice

`TaskForm.vue`'s save path (`store`/`update`) returns `back()->withErrors(['bukti' => ...])->with('error', ...)`. Because `withErrors` makes Inertia treat the response as a validation error, `onError` fires — so here the single source is the `onError` callback, and the old `watch(flash.error, ..., { immediate: true })` was removed. That removal kills two problems at once: the double toast on the `withErrors + with('error')` path, and the stale-flash toast that `immediate: true` would raise on first form open. The happy-path `onSuccess` toast ("Draf tersimpan.") is unchanged. This asymmetry (TaskForm uses `onError`, TaskList uses flash) is correct because the two controllers signal failure differently — form save via `withErrors` (422-like), submit via plain `with('error')` (302). (confirmed)

### Client-side guard in SelfieCapture

`SelfieCapture.vue` is a new component wrapping camera capture + file-upload fallback. The size guard `dalamBatas()` runs on both the file-input path (`onFile`) and the camera-capture path (`ambil`), emitting `tooLarge` and skipping `captured` for oversize files so they never enter `form.foto_selfie`. Default `maxSizeMb` is 5 and is passed explicitly from `TaskForm` as `:maxSizeMb="MAKS_SELFIE_MB"`. For `bukti`, `onBukti` filters oversize files out of `form.bukti` and toasts per file, and the PrimeVue `@error`/`invalidFileSizeMessage` path is also wired as a secondary guard. The guard is the primary line of defense as required, with the backend messages as backstop. (confirmed)

### Test coverage

Added: `test_selfie_oversize_menampilkan_pesan_ukuran` (6 MB selfie → error on `foto_selfie.0`, message contains "5 MB", not "failed to upload"), `test_bukti_oversize_menampilkan_pesan_ukuran` (11 MB → "10 MB", not "failed to upload"), and `test_pesan_uploaded_kustom_terdaftar_untuk_foto_selfie` (asserts the `uploaded` messages are registered with the right MB text — the backstop for the `UPLOAD_ERR_INI_SIZE` case that can't be triggered through a fake upload). `KatimkerSubmitTest::test_submit_tanpa_selfie_ditolak` gains `assertSessionMissing('success')`, the server-side guarantee for Bug 2.

Not tested: the `PostTooLargeException` handler (depends on `php.ini` `post_max_size`; evidence flags it as manual-verify and the handler is registered and builds). The frontend double-toast behavior itself is not covered by an automated test — reasonable given there's no JS test harness in the project; the server-side `assertSessionMissing('success')` is the closest proxy. Evidence reports 160 passed (606 assertions) for the full suite and a clean `npm run build`; per instructions these were not re-run. (confirmed)

### Blast radius

The validation `rules()` are untouched; only `messages()` gained entries. `bootstrap/app.php` adds one `render` handler scoped to non-`api/*` web requests and returns the default for API, so API behavior is unchanged. The two new Vue files and the `TaskForm` edits are confined to the Katimker task flow. No routes, enums, DB schema, policies, or the existing `bukti`/`dokumen` handling changed. The earlier fix under `.agents/tasks/fix-upload-bukti-tugas/` is not regressed (its module tests remain green per the full-suite run). (confirmed)

</details>

<details>
<summary>Files changed</summary>

- `app/Http/Requests/TaskDraftRequest.php` — custom Indonesian messages for `*.max` (reworded) and new `*.uploaded` on `foto_selfie`/`bukti`/`dokumen`; rules unchanged.
- `bootstrap/app.php` — `PostTooLargeException` render handler returning a flash-error for web requests, default for API.
- `resources/js/Components/form/SelfieCapture.vue` — new component; client-side 5 MB guard on both file-pick and camera paths.
- `resources/js/Pages/Katimker/TaskForm.vue` — size constants, oversize toasts for selfie/bukti, FileUpload `maxFileSize`/`invalidFileSizeMessage`/`@error`, removed stale `immediate` flash-error watcher.
- `resources/js/Pages/Katimker/TaskList.vue` — new/replaced; flash-driven success/error watchers, removed `onSuccess` toasts from submit/delete.
- `tests/Feature/KatimkerTaskTest.php` — three new tests for oversize messages and registered `uploaded` messages.
- `tests/Feature/KatimkerSubmitTest.php` — `assertSessionMissing('success')` on failed submit.

Full diff: `git show HEAD`.

</details>
