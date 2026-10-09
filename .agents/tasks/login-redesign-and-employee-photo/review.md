# Login redesign and SIKEP employee-photo avatar

The change rebuilds the login page into a modern split layout (branding panel + form card) in Indonesian and threads a SIKEP employee photo through the user avatars in the topbar and sidebar. A new nullable `token_pegawai` column on `users` is populated from SIKEP on sync; the Inertia middleware derives a `foto_url` server-side from a configurable base URL and shares it on `auth.user`, so the frontend only ever sees a string or null and never the raw token. All three avatar sites render an `<img>` with `object-cover rounded-full` and fall back to `pi pi-user` on null or image error. The login contract (field names `login`/`password`/`remember`, `route('login')`, `errors.login`) is preserved, and the theme button (`<FloatingConfigurator />`) stays.

Watch for: nothing blocking. One informational point — the sidebar user card is a newly added element (with its own logout button) rather than a pre-existing avatar container; it satisfies the brief and uses theme tokens correctly (confirmed).

**Verdict**: APPROVED

## High-level view

The login page moves from the stock Sakai centered-card to a responsive 12-column split: a gradient branding panel built on `var(--p-primary-*)` on the left (hidden under `lg`), and the form card on the right. The form keeps `useForm({ login, password, remember })`, posts to `route('login')`, resets the password on finish, and surfaces `form.errors.login` both as a top-of-form `<Message severity="error">` banner and as the per-field `<small class="text-red-500">`. Field names, route, error key, and the username/no_absen/no_pegawai contract are all untouched. Text is Indonesian, there are no fake links (the old register/forgot-password links are gone), and all color comes from surface/primary/muted tokens so dark mode follows.

The photo pipeline is server-driven. `config/sikep.php` adds `foto_base_url` from `SIKEP_FOTO_BASE_URL`, documented in both `.env` and `.env.example` without clobbering existing keys. A new standalone migration adds a nullable `token_pegawai` string after `no_absen` with a working `down()`. `User` adds the column to `#[Fillable]`, sets it in `syncFromSikep`, and documents it in the PHPDoc; `SikepUser` documents the source column too. `HandleInertiaRequests::share()` builds `foto_url` as `rtrim(base,'/').'/'.token.'.jpg'` only when the token is non-empty, else null, and does not leak the token.

The three avatar sites (topbar trigger, topbar dropdown panel, sidebar card) read `user?.foto_url` defensively, render the photo in their existing round containers, and flip a local error ref to the `pi pi-user` glyph on `@error` or null, so there is no broken-image state. Dropdown toggle, logout, role tags, and layout are preserved; the panels use theme-token surfaces so light/dark both resolve.

<details>
<summary>Issues (1)</summary>

1. **Sidebar logout duplicates topbar (informational)** — the new sidebar user card adds a second "Keluar" button alongside the topbar dropdown logout. Not a defect and within the brief; flag only if a single logout entry point is desired.

</details>

<details>
<summary>Details</summary>

### Login contract preserved under the redesign

The form model is `useForm({ login: '', password: '', remember: false })`, `submit()` posts to `route('login')` with `onFinish: () => form.reset('password')`, and the field is `v-model="form.login"` with `autocomplete="username"` plus the password `autocomplete="current-password"`. The label "Username / No. Absen / No. Pegawai" and placeholder keep the three-way login contract visible to the user without touching the backend. `:class="{ 'p-invalid': form.errors.login }"` and `<small v-if="form.errors.login" class="text-red-500">` are both retained, and a top-of-form `<Message severity="error" v-if="form.errors.login">` makes SIKEP-unavailable / no-role messages prominent — the "informative" part of the brief — without introducing a new error key (confirmed against the diff and the fixed `'login' => ...` contract noted in the plan).

The register and forgot-password `<Link>`s were removed along with the `Link` import, which is correct: there is no `password.request` route and no registration flow (the `RegisterController` and `Register.vue` are deleted in this same working tree), so keeping them would have been the fake-link the brief forbids.

### Split layout and Sakai conformance

The page uses `grid grid-cols-12` with the branding panel at `lg:col-span-6 xl:col-span-7` (hidden below `lg`) and the form at `col-span-12 lg:col-span-6 xl:col-span-5`, so on mobile the branding collapses and the form stacks full-width with its own logo header. The branding gradient is built from `var(--p-primary-600/500/400)` and the form side uses `bg-surface-50 dark:bg-surface-950`; headings use `text-surface-900 dark:text-surface-0` and secondary copy `text-muted-color`. That matches the Sakai token rules — no raw color values, light/dark paired. Components (`<Message>`, `<IconField>`, `<InputIcon>`, `<InputText>`, `<Password>`, `<Checkbox>`, `<Button>`) are all global PrimeVue, consistent with the skill. `<FloatingConfigurator />` is retained and, being `position:fixed`, overlays the split layout unchanged.

One nuance worth noting: the branding panel copy is white on a primary gradient and does not use `dark:` variants. That is intentional and correct — the gradient is dark enough in both themes that fixed white text stays legible; it is the one place where fixed color is appropriate rather than a token slip.

### Server-side photo URL and token confidentiality

`HandleInertiaRequests::share()` now resolves `auth.user` through a closure that computes:

```php
$fotoUrl = ! empty($u->token_pegawai)
    ? rtrim((string) config('sikep.foto_base_url'), '/').'/'.$u->token_pegawai.'.jpg'
    : null;
```

This matches the brief exactly: URL assembled server-side, `null` when no token, and the raw `token_pegawai` is never placed in the shared payload (only `foto_url`, `id`, `sikep_user_id`, `nama`, `username`, and role flags are exposed). Wrapping `user` in `fn () => ...` makes it a lazy Inertia prop, which is a small improvement over the previous eager array. The URL is built from `config('sikep.foto_base_url')`, which defaults to the production host and is overridable via `SIKEP_FOTO_BASE_URL` — present in both `.env` (line 43) and `.env.example` inside the existing SIKEP block, with existing keys intact (confirmed).

### Migration and model wiring

`2026_10_07_100001_add_token_pegawai_to_users_table.php` is a new standalone migration (the existing `create_users_table` is untouched) adding `$table->string('token_pegawai')->nullable()->after('no_absen')` with a `down()` that drops the column. `no_absen` exists in the base users table, so the `after()` anchor is valid. `User` adds `token_pegawai` to `#[Fillable]`, sets `'token_pegawai' => $sikep->token_pegawai` in `syncFromSikep`, and adds `@property string|null $token_pegawai` to the class docblock; `SikepUser` lists `token_pegawai` in both its column comment and `@property` block. The plan records `php artisan migrate --force` running this migration (DONE, exit 0) and tinker confirming the column exists and is fillable — evidence is present and specific, so no re-run is warranted.

### Avatar fallback across the three sites

All three sites follow the same defensive pattern. The topbar keeps separate error refs for the trigger and the dropdown panel (`triggerImgError` / `panelImgError`) so a failure in one does not blank the other, with `showTriggerPhoto` / `showPanelPhoto` gating `user?.foto_url && !error`. The sidebar uses `sidebarImgError` / `showSidebarPhoto`. Each `<img>` carries `@error` to flip its ref, and the `v-else` branch is the original `pi pi-user` glyph, so a 404 or null token resolves to the icon with no broken-image box. The images sit inside the existing round containers (`1.5rem` trigger, `2.5rem` panel, `2.25rem` sidebar) with `object-cover rounded-full` and `overflow-hidden` on the span, matching the brief. `user` is read as `page.props.auth?.user ?? null` with optional chaining throughout.

The topbar dropdown preserves the `v-styleclass` toggle, the role `<Tag>`s (now derived from `is_admin`/`is_katimker`/`is_kabid` into an Indonesian `peran` list), the defensive `user?.no_absen` read, and a logout button. The panel and sidebar card draw their surfaces from `--p-surface-card` / `--p-surface-ground` / `--p-surface-border`, so dark mode is handled by the token layer rather than per-element `dark:` classes.

### Build evidence

The plan records `npm run build` at exit 0 with the `Login-*`, `message-*`, and iconfield/inputtext chunks emitted. Spot-checking `public/build/manifest.json` confirms a `message` chunk is present, consistent with the new `<Message>` and `<IconField>` usage, so the build evidence holds and no rebuild is needed. Runtime visuals (live photo load, dark-mode render) are browser-only and left unverified; everything checkable statically holds.

</details>

<details>
<summary>File map</summary>

- `config/sikep.php` — adds `foto_base_url` key from `SIKEP_FOTO_BASE_URL`.
- `.env`, `.env.example` — document `SIKEP_FOTO_BASE_URL` in the SIKEP block.
- `database/migrations/2026_10_07_100001_add_token_pegawai_to_users_table.php` — new migration, nullable `token_pegawai` after `no_absen`, with `down()`.
- `app/Models/User.php` — `token_pegawai` in Fillable, set in `syncFromSikep`, PHPDoc property.
- `app/Models/Sikep/SikepUser.php` — documents `token_pegawai` source column.
- `app/Http/Middleware/HandleInertiaRequests.php` — shares `auth.user.foto_url` built server-side.
- `resources/js/Pages/Auth/Login.vue` — split-layout redesign, Indonesian, `<Message>` banner, preserved contract.
- `resources/js/layout/AppTopbar.vue` — photo avatar in trigger + dropdown panel with fallback; RSUD logo.
- `resources/js/layout/AppSidebar.vue` — photo avatar in user card with fallback.

Full diff: `git -C f:\LARAVEL\remunisasi_v2 diff`.

</details>
