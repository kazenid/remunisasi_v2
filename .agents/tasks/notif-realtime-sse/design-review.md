# Design Review — Sistem Notifikasi Realtime (SSE + Redis Pub/Sub)

Reviewed document: `.agents/tasks/notif-realtime-sse/design.md` (revisi pass ke-4).
Codebase re-verified against the worktree `f:\LARAVEL\remunisasi_v2\.worktrees\notif-realtime-sse` (absolute paths), with framework/vendor claims cross-checked in the installed `vendor/` of the main workspace where the worktree shares the same framework version.

This is a fresh, standalone review that re-opens the two MEDIUM findings and four NIT findings carried in the prior `design-review.json` (verdict CHANGES_REQUESTED) and checks each against the actual source — not against the design's own claim of closure.

---

## Verdict rationale

The two MEDIUM findings from the prior pass are the focus:

- **Prior MED #1 (R4-1): SSE re-subscription after a read-timeout `RedisException`.** The design now mandates DISCARD + REBUILD of the dedicated connection on every `\RedisException` before the next `subscribe()`, via `Redis::purge('notifications')` → `Redis::connection('notifications')` → re-apply `OPT_READ_TIMEOUT=1`. I verified `Illuminate\Redis\RedisManager::purge($name)` exists and does `unset($this->connections[$name])`, so the next `connection()` builds a fresh socket rather than reusing the broken subscriber handle. The acceptance criterion (AC #12, §1.4) now explicitly requires a REAL message delivered AND continued delivery after ≥60s idle (≥3 heartbeats), with a concrete manual procedure in §12.3. This directly and deterministically resolves the concern. **Resolved.**
- **Prior MED #2 (R4-2): module-singleton EventSource ownership vs per-page AppLayout.** The design now settles (not hedges) that the project uses per-page (non-persistent) layouts, moves EventSource ownership to module scope with an `initialized` guard, makes `init()` a no-op after first mount, and explicitly forbids `es.close()` in the layout `onUnmounted` — teardown happens only on `beforeunload`/`pagehide`. I verified every authenticated page sets `defineOptions({ layout: AppLayout })` (per-page form), `resources/js/app.js` wires no persistent layout, and `resources/js/Layouts/AppLayout.vue` has no `onUnmounted`/lifecycle hook at all. The design's claim matches the source exactly. **Resolved.**

All four prior NIT findings (R4-3 write-path timestamps, R4-4 morph map, R4-5 list-vs-detail guard, R4-6 15/20 limit) are addressed normatively in §3.1, §1.6, §9.1, and §8 respectively, and each underlying source fact re-checks out.

One new NIT surfaced during verification of the R4-1 rebuild path (socket of the discarded connection is not explicitly closed on rebuild; it relies on GC/destruct). It is NIT severity and does not affect the verdict.

Counting severities below: **0 HIGH, 0 MEDIUM, 1 NIT**. Per the mechanical rule (any HIGH or MEDIUM ⇒ CHANGES_REQUESTED; otherwise APPROVED), the verdict is **APPROVED**.

---

## Findings

### 1. [NIT] `Redis::purge()` on rebuild unsets the cached connection but does not close the discarded socket; the design should note GC/destruct handling

Location: §6.2 `$connect()` helper (`Redis::purge('notifications')` → `Redis::connection('notifications')`).

Verified: `Illuminate\Redis\RedisManager::purge($name)` is `unset($this->connections[$name]);` only — it removes the manager's cached reference but does NOT call `disconnect()`/`close()` on the previous `Connection`/raw client. In the design's closure the old `$connection`/`$client` are then overwritten by the rebuilt pair, so the previous objects become unreferenced and phpredis closes the socket on `__destruct`. That is correct in practice, but on an idle connection the rebuild runs roughly once per `OPT_READ_TIMEOUT` second, so for a brief window two sockets can coexist until GC runs. The design asserts a "socket baru yang bersih" without stating that the old socket is released by destruct rather than by an explicit close.

Concrete fix: add one sentence to §6.2 — "`Redis::purge()` hanya membuang referensi ter-cache; socket koneksi lama ditutup saat objek `Connection`/raw client lama tidak lagi direferensikan (destruct phpredis). Tidak perlu `close()` eksplisit pada jalur rebuild karena variabel `$connection`/`$client` langsung ditimpa; `finally` tetap menutup socket aktif terakhir sekali." Optionally, if an implementor prefers determinism over relying on GC, call `try { $client->close(); } catch (\Throwable) {}` immediately before `Redis::purge()` in `$connect()` when `$client` is already set. This is documentation hygiene, not a correctness defect.

---

## Verified Assumptions (checked against actual source this pass)

1. **`Illuminate\Redis\RedisManager::purge($name)` exists** and does `unset($this->connections[$name])` (read `vendor/.../Redis/RedisManager.php`). The R4-1 fix mechanism (`Redis::purge('notifications')` to force a fresh socket on the next `connection()`) is valid. `connection()` lazily rebuilds via `resolve()` when the cache entry is gone.
2. **`Illuminate\Redis\Connections\Connection::client()` exists** (returns `$this->client`, the raw phpredis handle) and `subscribe()` exists on the base `Connection` (`vendor/.../Redis/Connections/Connection.php`, line 81 / `subscribe`). So `$connection->client()->setOption(\Redis::OPT_READ_TIMEOUT, 1)` and `$connection->subscribe([$channel], $cb)` are valid calls.
3. **Per-page (non-persistent) layout is universal.** Grep across `.worktrees/notif-realtime-sse/resources/js/Pages/**/*.vue` shows every authenticated page uses `defineOptions({ layout: AppLayout })` (Dashboard, Kabid/ReviewList, Kabid/ReviewDetail, Katimker/TaskList, Katimker/TaskDetail, all Admin pages, Rekap, UIKit; guest pages use `GuestLayout`). The R4-2 premise is correct.
4. **`resources/js/app.js` wires no persistent layout.** Read in full: `createInertiaApp` with a plain `resolve`/`setup`; no global `.layout`, no persistent-layout callback. Confirms AppLayout is re-created per navigation.
5. **`resources/js/Layouts/AppLayout.vue` has no lifecycle hook.** Read in full: `<script setup>` only imports `useLayout` and computes `containerClass`; there is no `onMounted`/`onUnmounted`. So the design's instruction to add `init(usePage())` on `onMounted` and to NOT add `es.close()` on `onUnmounted` is consistent with the current empty-lifecycle component.
6. **Latest migration is `2026_10_09_100002_create_incentive_results_table.php`** (file_search confirms the file exists in both the worktree and main). The "new migration name must sort after this; no FK to users" guidance in §3.1/§13 is correct.
7. **Global redis prefix exists.** `config/database.php` → `redis.options.prefix = {app-slug}-database-` (verified). The `notifications` connection's `'options' => ['prefix' => '']` is a correct, necessary override so pub/sub channel names are literal `notif.user.{id}`.
8. **Service shapes match the design's wiring plan.**
   - `TaskService::create(): TaskReport` returns `$task` from inside `DB::transaction(...)`; `update(): TaskReport` saves `$statusLama = $task->status` before the transaction and returns `$task->refresh()`. The "capture-to-variable, notify post-commit, gate update on `$statusLama !== STATUS_DIAJUKAN`" plan fits.
   - `ReviewService::approve(): TaskReport` returns inside a transaction (and may call `ubahJenis()`); `revisi()`/`reject()` both `return $this->kembalikan(...)` which runs its own transaction and returns `$task->refresh()`. Notifying in the public `revisi()`/`reject()` after capturing the result is post-commit and correct.
   - `AssignmentService::createForKatimker(int $aktorSikepUserId, int $katimkerSikepUserId, array $data, bool $aktorAdmin = false): TaskReport` returns `DB::transaction(fn () => ... return $task)`; recipient is the `$katimkerSikepUserId` argument. Matches §4/§5.1.
   - All three services currently inject only `AuditLogger` via constructor (`AssignmentService::__construct(private readonly AuditLogger $audit)`), so adding `NotificationService` to the constructor follows the existing DI pattern.
9. **`ReviewController::assignStore(Request, AssignmentService $assignment): RedirectResponse`** method-injects `AssignmentService` and calls `$assignment->createForKatimker(...)` WITHOUT capturing the return before redirect. This confirms the §5.1 rationale that notify must live in the service layer (no post-commit point in the controller).
10. **`reject()` passes `'task.reject'` as the AUDIT action string**, which is a different concern from the notification `type` `task.rejected` used in the design. No conflict — the design's notification types are independent of audit action strings.

## Unverified / Residual-Risk Assumptions

1. **phpredis honors `OPT_READ_TIMEOUT=1` on `subscribe()` on the production Docker php image.** The design no longer depends on phpredis throwing (the outer loop drives heartbeat/abort regardless), AND it now rebuilds the connection on `\RedisException` so re-subscribability after a timeout is handled. The ONLY remaining precondition is that `subscribe()` does not block *past* the read-timeout forever; §6.2 states this as an implementor-must-verify item and §12.3/AC #12 give the concrete test (observe `: ping` every ~20s via `curl -N`, then prove a real `data:` message still arrives after ≥60s idle). This is correctly scoped as a runtime verification gate, not a design gap.
2. **nginx/php-fpm file contents** (`docker/nginx/default.conf`: `gzip on` global, `fastcgi_pass app:9000`, `fastcgi_buffers`; `docker/php/php-fpm.conf`: `pm.max_children = 50`, `pm.max_requests = 500`, no `request_terminate_timeout`). The design claims to have read these this pass; filenames are stated as verified in the worktree. Not independently re-read here (low risk — §10.1/§10.2 edits are additive/location-scoped). Re-confirm directives before applying.
3. **Production `docker-compose.yml` mismatch** (bundled PostgreSQL + Redis containers vs the approved MySQL + host Redis/MySQL architecture). The design correctly scopes compose alignment OUT of the application-code change and defers to the user (consistent with the user's original request to move DB/Redis to the Linux host and switch to MySQL). No code impact; flagged only so the implementor does not silently "align" compose without raising it.
