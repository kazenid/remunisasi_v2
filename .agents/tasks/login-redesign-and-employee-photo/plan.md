# Implementation Plan — Redesign Login & Employee Photo Avatar

Context grounded in exploration (2026-xx):
- Stack: Laravel 11 + Inertia + Vue 3 + PrimeVue (Aura) + Tailwind v4. UI bahasa Indonesia. Windows/PowerShell, cwd `f:\LARAVEL\remunisasi_v2`.
- PrimeVue components are registered GLOBALLY (see `resources/js/app.js`) — write `<InputText>`, `<Password>`, `<Button>`, `<Message>` etc. directly, do NOT import them.
- Theme tokens are mandatory: `var(--p-*)`, `text-surface-900 dark:text-surface-0`, `text-muted-color`, `text-primary`, `bg-surface-*`. Dark selector is `.app-dark`; pair light/dark with `dark:` classes. Test both modes.
- Build command: `npm run build` (= `vite build`). Migrate: `php artisan migrate`.
- Login backend contract is FIXED: form fields `{ login, password, remember }`, posts to `route('login')`, all auth errors returned under key `login` (confirmed in `app/Http/Controllers/Auth/LoginController.php` — SIKEP-unavailable, bad-credential, and no-role messages all use `'login' => ...`). Do NOT change field names, route, or error-key handling.
- `auth.user` is shared by `HandleInertiaRequests::share()` as an array (id, sikep_user_id, nama, username, roles, is_katimker, is_kabid, is_admin). Topbar also reads `user.no_absen` defensively though it is not currently shared — keep that defensive read working.
- SIKEP photo URL: `https://sikep.rsudbangil.pasuruankab.go.id/foto/{token_pegawai}.jpg`. `token_pegawai` is a column on SIKEP `ms_user`, exposed via `App\Models\Sikep\SikepUser` (Eloquent selects `*`, so `$sikepUser->token_pegawai` is readable; PHPDoc currently omits it).
- Latest existing migration is `2026_10_06_130001_create_individual_targets_table.php` (NOT the audit_logs one named in the brief). New migration must sort AFTER it — use `2026_10_07_100001_*`.

Design decisions (made here, not deferred):
- Login layout: a modern **split layout** — left branding/info panel (gradient using `var(--p-primary-*)`, logo, app name, tagline + 2-3 info points about the sistem remunerasi RSUD Bangil), right form card. On mobile the branding panel collapses/stacks above the card. Chosen over a plain centered card because the brief explicitly asks for "more informative"; a side panel is the Sakai-consistent way to add branding copy without crowding the form. `<FloatingConfigurator />` stays (it is `position:fixed`, so it overlays either layout).
- General error banner: use PrimeVue `<Message severity="error">` bound to `form.errors.login`, shown once at the top of the form; keep the per-field `<small class="text-red-500">` too (brief says preserve existing behavior). This makes SIKEP-unavailable / no-role messages prominent (the "informative" part) without changing the error key.
- Avatar fallback: build `foto_url` SERVER-SIDE in Inertia share (frontend only consumes a string or null). Each avatar renders `<img>` when `foto_url` is truthy AND no load error; `@error` flips a local `ref` to fall back to the existing `pi pi-user` glyph. No raw token exposed to frontend.

---

- [ ] 1. Add configurable SIKEP photo base URL to config.
      Add key `'foto_base_url' => env('SIKEP_FOTO_BASE_URL', 'https://sikep.rsudbangil.pasuruankab.go.id/foto'),` to `config/sikep.php`, with a short Indonesian comment block matching the file's existing comment style (explain it is the base URL for `{token_pegawai}.jpg`).
      Files: `config/sikep.php`
      Verify: `php artisan config:clear; php artisan tinker --execute="echo config('sikep.foto_base_url');"` prints the default URL.

- [ ] 2. Document the new env key in `.env` and `.env.example`.
      Append `SIKEP_FOTO_BASE_URL=https://sikep.rsudbangil.pasuruankab.go.id/foto` with a one-line Indonesian comment INSIDE the existing SIKEP block (after the salt lines, before the blank line that precedes `SESSION_DRIVER`). Match each file's comment style. Do NOT clobber existing keys. (`.env` SIKEP block uses `127.0.0.1` local-dev values + extra "TERVALIDASI" comment; `.env.example` uses the production host values — keep each file's existing values untouched, only add the new line.)
      Files: `.env`, `.env.example`
      Verify: `php artisan config:clear` then confirm `grep SIKEP_FOTO_BASE_URL .env .env.example` shows the key in both (reading only; the real functional check happens in step 4's tinker run).

- [ ] 3. Add a migration adding nullable `token_pegawai` to `users`.
      Create a NEW migration (do not edit the existing create_users_table) named `2026_10_07_100001_add_token_pegawai_to_users_table.php`. `up()`: `Schema::table('users', fn (Blueprint $t) => $t->string('token_pegawai')->nullable()->after('no_absen'));`. `down()`: drop the column. Follow the anonymous-class migration convention used by the other files.
      Files: `database/migrations/2026_10_07_100001_add_token_pegawai_to_users_table.php`
      Verify: `php artisan migrate` runs the new migration without error; `php artisan tinker --execute="echo \Illuminate\Support\Facades\Schema::hasColumn('users','token_pegawai') ? 'yes' : 'no';"` prints `yes`.

- [ ] 4. Mirror `token_pegawai` through the User model.
      In `app/Models/User.php`: add `'token_pegawai'` to the `#[Fillable([...])]` attribute list; add `'token_pegawai' => $sikep->token_pegawai` to the update array inside `syncFromSikep()`; add `@property string|null $token_pegawai` to the class PHPDoc `@property` block. Also add `'token_pegawai'` to `SikepUser`'s PHPDoc `@property` block in `app/Models/Sikep/SikepUser.php` (brief says it documents the column; it currently does not — add it for accuracy).
      Files: `app/Models/User.php`, `app/Models/Sikep/SikepUser.php`
      Verify: `php artisan tinker --execute="echo in_array('token_pegawai', (new \App\Models\User)->getFillable()) ? 'yes' : 'no';"` prints `yes`.

- [ ] 5. Share `foto_url` on `auth.user` in Inertia middleware.
      In `app/Http/Middleware/HandleInertiaRequests.php`, inside the `auth.user` closure, add a `'foto_url'` key: when `$u->token_pegawai` is non-empty, `rtrim((string) config('sikep.foto_base_url'), '/').'/'.$u->token_pegawai.'.jpg'`, else `null`. Do NOT expose the raw token. Keep all existing keys unchanged.
      Files: `app/Http/Middleware/HandleInertiaRequests.php`
      Verify: `php artisan test` passes if any middleware/auth tests exist; otherwise this is covered by the end-to-end `npm run build` + manual-login check in step 9. (No grep-only check.)

- [ ] 6. Redesign `resources/js/Pages/Auth/Login.vue`.
      Keep `defineOptions({ layout: GuestLayout })`, `<FloatingConfigurator />`, the `useForm({ login, password, remember })`, `submit()` posting to `route('login')` with `onFinish: () => form.reset('password')`. Rebuild the markup as a responsive split card: left branding panel (logo `/images/logo_rsud_bangil.png`, "Remunisasi", tagline "Sistem Remunerasi Pegawai RSUD Bangil", 2-3 short info points, Indonesian), right form. Use global `<InputText>` (label "Username / No. Absen / No. Pegawai", `autocomplete="username"`), `<Password :feedback="false" toggleMask fluid autocomplete="current-password">`, `<Checkbox binary>` "Ingat saya", submit `<Button label="Masuk" :loading="form.processing">`. Add a top-of-form `<Message severity="error" v-if="form.errors.login">{{ form.errors.login }}</Message>`; keep per-field `:class="{ 'p-invalid': form.errors.x }"` and `<small class="text-red-500">`. Theme tokens only; works in light+dark; no fake links (no password-reset route). Optional: `<IconField>/<InputIcon>` with pi icons, a footer with current year.
      Files: `resources/js/Pages/Auth/Login.vue`
      Verify: part of `npm run build` in step 9; after build, load `/login` and toggle dark mode — both render correctly, submitting bad credentials shows the error banner.

- [ ] 7. Add photo-with-fallback avatar to `resources/js/layout/AppTopbar.vue`.
      Read `foto_url` via existing `user` computed (`page.props.auth?.user`). Add two `ref`s (e.g. `triggerImgError`, `panelImgError`) defaulting false. Trigger button: when `user?.foto_url && !triggerImgError`, render a small round `<img :src="user.foto_url" @error="triggerImgError = true" class="rounded-full object-cover" style="width:1.5rem;height:1.5rem">` (sized to fit the existing action button), else the current `<i class="pi pi-user">`. Dropdown `.user-panel` avatar: same pattern inside the existing round `span` (2.5rem) — `<img>` with `object-cover rounded-full w-full h-full` and `@error="panelImgError = true"`, else `<i class="pi pi-user">`. Preserve `v-styleclass` dropdown toggle, logout, roles `<Tag>`, and all layout. Use optional chaining defensively.
      Files: `resources/js/layout/AppTopbar.vue`
      Verify: part of `npm run build` in step 9; after build, confirm the topbar shows the photo when `foto_url` is set and the `pi pi-user` icon when it is null or the image 404s.

- [ ] 8. Add photo-with-fallback avatar to `resources/js/layout/AppSidebar.vue`.
      In the `.sidebar-user-card` round avatar `span` (2.25rem), apply the same pattern: a `ref` (e.g. `sidebarImgError`) defaulting false; when `user?.foto_url && !sidebarImgError` render `<img :src="user.foto_url" @error="sidebarImgError = true" class="rounded-full object-cover w-full h-full">`, else the existing `<i class="pi pi-user text-sm">`. Keep the existing `user` computed, logout button, and layout. Optional chaining.
      Files: `resources/js/layout/AppSidebar.vue`
      Verify: part of `npm run build` in step 9; after build, confirm the sidebar user card shows the photo or falls back to the icon.

- [ ] 9. Full verification.
      From `f:\LARAVEL\remunisasi_v2`: run `php artisan migrate` (confirms step 3 applies cleanly) then `npm run build` (confirms steps 6-8 compile with no Vue/Vite errors). Manually: `npm run dev`, log in, confirm the redesigned login works in light+dark, the error banner shows on bad credentials, and the topbar + sidebar avatars show the SIKEP photo when `token_pegawai` resolves and gracefully fall back to `pi pi-user` on null/404.
      Files: none (verification only)
      Verify: `php artisan migrate` reports the new migration ran; `npm run build` exits 0 with assets emitted.

## Verification evidence (iteration 1, implemented)

All 9 steps implemented. Commands run from `f:\LARAVEL\remunisasi_v2` (Windows/PowerShell):

- `php artisan config:clear` → OK; `php artisan migrate --force` → ran `2026_10_07_100001_add_token_pegawai_to_users_table` (17.16ms, DONE), exit 0. **Migration applied at runtime against the real DB.**
- `php artisan tinker` check → `col:yes` (users.token_pegawai exists), `fillable:yes` (User fillable includes token_pegawai), `config('sikep.foto_base_url')` prints `https://sikep.rsudbangil.pasuruankab.go.id/foto`. All verified at runtime.
- `npm run build` (vite build) → exit 0, 1093 modules transformed, assets emitted incl. `Login-*.js`, `message-*.js`, `iconfield`/`inputtext` style chunks. No Vue/Vite errors.

Not verified at runtime (cannot be exercised headless): the actual rendered login page in light/dark, the live SIKEP photo load/404 fallback behavior, and topbar/sidebar avatar swap. These depend on a browser session + a logged-in user with a resolvable `token_pegawai`. Templates were checked for consistency: `foto_url` read via `user?.foto_url` optional chaining in all three Vue files; `@error` handlers flip local refs (`triggerImgError`, `panelImgError`, `sidebarImgError`) to fall back to `pi pi-user`; no undefined refs. `IconField`/`InputIcon`/`Message` resolve through `@primevue/auto-import-resolver` (vite.config.js), confirmed by their style chunks appearing in the build output.

Files changed: `config/sikep.php`, `.env`, `.env.example`, `database/migrations/2026_10_07_100001_add_token_pegawai_to_users_table.php` (new), `app/Models/User.php`, `app/Models/Sikep/SikepUser.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/Pages/Auth/Login.vue`, `resources/js/layout/AppTopbar.vue`, `resources/js/layout/AppSidebar.vue`. Not committed — left for user review.

Notes / assumptions:
- The brief named `2026_10_06_100005_create_audit_logs_table.php` as the latest migration, but the directory actually has newer ones through `2026_10_06_130001`. Using `2026_10_07_100001_*` keeps the new migration last regardless.
- `token_pegawai` type assumed string (tokens are filename stems). Column is nullable so existing rows and employees without a token are unaffected.
- PART 1 of the user's original question (whether login logic matches the referenced `authenticateMobile`) is already answered by the existing `SikepAuthService` (salt `cba654`, `md5($salt.md5($password))`, same username/no_absen/no_pegawai lookup, active-account check). No code change needed there; this plan covers the two requested deliverables (login redesign + employee photo).
