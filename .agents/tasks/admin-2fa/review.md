# Admin 2FA (TOTP) enforcement for the SIKEP login flow

Adds mandatory TOTP two-factor for `is_admin` users layered onto the existing SIKEP login, without touching the non-admin path. A confirmed admin who passes the SIKEP password check is held in a pending-2FA session (never `Auth::login`'d) and redirected to a challenge; an unconfirmed admin is logged in but trapped at setup by `EnsureTwoFactor` until enrollment completes. Secret and recovery codes are encrypted at rest via model mutators, TOTP/QR are backed by `pragmarx/google2fa` + `bacon/bacon-qr-code`, and two Sakai/GuestLayout Vue pages drive challenge and enrollment. This review gates the implemented diff against the APPROVED design and the coder's verification.md.

Watch for: nothing blocking. Implementation matches the finalized design on every security invariant (confirmed by reading the code), and verification.md records the required runtime evidence (107 passed, route list, migrate, build). Two non-blocking observations noted below.

**Verdict**: APPROVED

## High-level view

The 2FA branch sits exactly where the design pins it in `LoginController::store()` — after `syncFromSikep`, before `Auth::login`, keyed on the already-resolved `$roles['is_admin']` rather than `$user->requiresTwoFactor()`. A confirmed admin only ever gets pending session state (`2fa.pending_user_id`, `2fa.remember`) plus a redirect to the challenge; `Auth::login` for that path lives solely in `verifyChallenge` after a valid factor. The single-source `is_admin` invariant and the sync-doesn't-touch-2FA invariant together close the first-login bypass window.

Recovery codes are single-use: `consumeRecoveryCode` normalizes once (strip non-alphanumeric + uppercase) on both sides of a `hash_equals`, drops the matched code, and re-encrypts the remainder, so a consumed code fails on reuse. Enforcement is a route-group-aliased `EnsureTwoFactor` that forces unconfirmed admins to setup for every request except setup/confirm/logout, redirects confirmed admins away from the enrollment routes (defense in depth over the controller guard), and never traps logout or confirmed users.

The secret never re-crosses to a confirmed admin: `setup()` redirects null/non-admin/confirmed users to dashboard before any secret is read or generated, and the middleware bounces confirmed admins off the setup routes anyway. At-rest encryption is owned entirely by the model mutators; `#[Hidden]` adds serialization defense; `HandleInertiaRequests` exposes only the two derived booleans. The one-time plaintext crossings (QR+secret on enrollment GET, recovery codes on the confirm/regenerate `Inertia::render`) are the controlled exceptions the design calls out.

The non-admin login path is byte-for-byte unchanged: SIKEP hash via `hash_equals`, master-password blocklist, generic "Kredensial yang diberikan tidak cocok", QueryException→SIKEP-unavailable, and no-role rejection all remain in `SikepAuthService`/`LoginController`. Challenge-verify, confirm, and regenerate all carry `throttle:two-factor`, and the limiter is registered in `AppServiceProvider::boot()` keyed on pending-id → user-id → IP. The two Vue pages are GuestLayout + page-level `FloatingConfigurator` + auto-imported PrimeVue + Indonesian labels + token colors with dark variants, matching `Login.vue`.

<details>
<summary>Issues (2)</summary>

1. **Bundled unrelated changes** — the working tree carries a large amount of work outside 2FA scope (Register removal, Dashboard, layout, Admin/Katimker/Kabid controllers and pages, many migrations/tests). Out of scope for this gate but should land in its own commit, not folded into the 2FA change.
2. **Manual QA still pending** — enrollment with a real authenticator, light/dark visual render, copy/download recovery codes, and 429 behavior are untested (headless-infeasible). Non-blocking; schedule as post-merge browser QA as verification.md states.

</details>

<details>
<summary>Details</summary>

### No pending-state bypass in LoginController::store

The 2FA branch is placed after `$user = User::syncFromSikep($sikepUser)` and before `Auth::login`, exactly as the design's ordering invariant requires (confirmed). It reads `$isAdmin = $roles['is_admin']` — the same resolved array used to reject role-less accounts — and does not call `$user->requiresTwoFactor()`, so the admit/enforce decision has a single source of truth. A confirmed admin takes:

```php
if ($isAdmin && $user->hasConfirmedTwoFactor()) {
    $request->session()->put('2fa.pending_user_id', $user->id);
    $request->session()->put('2fa.remember', $request->boolean('remember'));
    return redirect()->route('two-factor.challenge');
}
```

No `Auth::login`, no `session()->regenerate()` on this path — both are deferred to `verifyChallenge` after the second factor. Since `syncFromSikep` writes only `username`, `nama`, `no_absen`, `token_pegawai`, `last_sync_at` (confirmed in `User.php`), `hasConfirmedTwoFactor()` reads the same DB row that `$roles['is_admin']` was resolved from, so the first-login window the design analyzes cannot admit a confirmed admin without a challenge. verification.md records the anti-bypass first-login assertion among the 10 passing `TwoFactorAuthTest` cases.

### Single-use recovery codes

`consumeRecoveryCode` normalizes with `strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v))` applied to both the input and each stored code, compares via `hash_equals`, skips the first match, keeps the rest in original `XXXX-XXXX` form, and re-saves through the encrypting mutator (confirmed). A consumed code is gone from the set, so reuse returns false. The empty-array→null mutator means a fully consumed set stores NULL rather than ciphertext `[]`. verification.md's `UserTwoFactorTest` covers the reuse-fails and normalization cases.

### Enforcement cannot be skipped, and no logout loop

`EnsureTwoFactor` resolves `$routeName` once, lets non-admins and null users through, and for admins splits on confirmation state. Unconfirmed admins are forced to `two-factor.setup` on every request except `two-factor.setup`, `two-factor.setup.confirm`, and `logout` — so logout is always reachable and never loops. It is aliased `two-factor` and applied at the `auth` route group (`['auth','two-factor']` in `routes/web.php`), so `$request->route()` is resolved when the name check runs; the design's rejected alternative (append to web group, risking a null route and a logout lockout) was not used. Confirmed admins pass everything except the two enrollment routes, where they are redirected to dashboard.

### Confirmed admin cannot re-expose the secret (HIGH #1)

`setup()` guards on the first line:

```php
if ($user === null || ! $user->requiresTwoFactor() || $user->hasConfirmedTwoFactor()) {
    return redirect()->route('dashboard');
}
```

The secret is read/generated only after this guard, so a confirmed admin, a non-admin, or a null user never reaches secret material. The middleware independently bounces confirmed admins off `two-factor.setup`/`two-factor.setup.confirm`, giving the layered defense the design specifies. `confirmSetup` and `regenerateRecoveryCodes` carry the same guard shape (regenerate requires `hasConfirmedTwoFactor()` to be true, the others require it false) — a tightening beyond the design's `confirmSetup` sketch that is consistent and correct.

### Encryption at rest and frontend exposure

`twoFactorSecret` and `twoFactorRecoveryCodes` are explicit encrypting accessor/mutators (null→null for the secret, empty→null for codes), both columns are in `#[Hidden]`, and `two_factor_confirmed_at` is cast datetime. The model layer owns at-rest encryption; nothing else writes raw values. `HandleInertiaRequests` adds only `two_factor_enabled` and `needs_two_factor_setup` booleans. The controlled plaintext crossings are the enrollment GET (`qrSvg` + base32 `secret`, only for an unconfirmed admin past the guard) and the confirm/regenerate `Inertia::render` carrying `recoveryCodes` once — the design's two sanctioned exceptions, both delivered via direct render (not flash, which `share()` would drop).

### DecryptException handling split by auth state

`verifyChallenge` (pre-login) catches `DecryptException`, reports, nulls the three columns via `forceFill`, clears pending state, and redirects to login with a generic admin-contact error — correct because the user is not yet authenticated and must not be sent to the auth-gated setup. `setup`/`confirmSetup` (post-login) reset and redirect to `two-factor.setup` to re-enroll. Matches the design's pre/post-login error table.

### Non-admin login path unchanged

`SikepAuthService::attempt` keeps the SIKEP `md5(salt1.md5(pw).salt2)` hash compared with `hash_equals`, the master-password blocklist, and null-on-any-failure. `LoginController::store` keeps the QueryException→"Integrasi SIKEP tidak tersedia", the generic "Kredensial yang diberikan tidak cocok", and the empty-roles rejection ahead of the 2FA branch. Non-admins fall straight through to `Auth::login` + regenerate + intended-dashboard. verification.md's `LoginTest` (5 passing) confirms no regression.

### Rate limiting

`two-factor.challenge.verify`, `two-factor.setup.confirm`, and `two-factor.recovery-codes.regenerate` all carry `throttle:two-factor` (confirmed in `routes/web.php`), and `AppServiceProvider::boot()` registers the limiter at 5/min keyed on `2fa.pending_user_id` → `user()->id` → IP, so the pending (pre-login) challenge is throttled per-account before a session exists. route:list in verification.md shows no "limiter not defined" error.

### Sakai conformance of the two Vue pages

Both pages use `defineOptions({ layout: GuestLayout })`, mount `<FloatingConfigurator />` in their own template, and rely on auto-imported PrimeVue (`InputOtp`, `InputText`, `Button`, `Message`) with no manual component imports — matching `Login.vue`. Labels are Indonesian, colors use theme tokens (`text-surface-900 dark:text-surface-0`, `text-muted-color`, `bg-surface-*`) with `dark:` variants throughout. `TwoFactorSetup` declares the four props with safe defaults and derives the mutually exclusive `faseRecovery` (`justConfirmed === true`) and `faseEnrollment` (`!justConfirmed && !!qrSvg`) computeds, so the confirm response never renders the QR block and the GET never renders recovery codes; `v-html` receives `qrSvg` only when truthy. The QR `v-html` carries server-generated SVG from our own data, not user input. Both pages also expose an explicit "Keluar" logout button, giving the trapped admin a sanctioned exit.

### Bundled unrelated changes

The working tree mixes the 2FA change with a large unrelated body of work: deleted Register controller/page, Dashboard and layout rewrites, new Admin/Katimker/Kabid controllers and pages, nine non-2FA migrations, and many feature tests. None of it is part of the 2FA gate and none of it conflicts with the invariants, but it should be committed separately from the 2FA change rather than folded in.

</details>

<details>
<summary>File map</summary>

- `app/Http/Controllers/Auth/LoginController.php` — 2FA branch inserted between syncFromSikep and Auth::login; destroy() clears pending state.
- `app/Http/Controllers/Auth/TwoFactorController.php` — new; challenge/verify/setup/confirm/regenerate with guards and DecryptException handling.
- `app/Http/Middleware/EnsureTwoFactor.php` — new; group-aliased admin enforcement.
- `app/Models/User.php` — encrypting mutators, hidden columns, hasConfirmedTwoFactor/requiresTwoFactor/consumeRecoveryCode.
- `app/Services/TwoFactor/TwoFactorService.php` — new; secret/URI/QR/verify/recovery-code generation.
- `app/Providers/AppServiceProvider.php` — two-factor rate limiter.
- `app/Http/Middleware/HandleInertiaRequests.php` — two non-sensitive 2FA booleans.
- `bootstrap/app.php` — two-factor alias registration.
- `routes/web.php` — challenge routes outside auth; setup/confirm/regenerate inside auth+two-factor with throttle.
- `config/twofactor.php` — new; issuer, recovery_code_count=8, window=1.
- `database/migrations/2026_10_07_100002_add_two_factor_columns_to_users_table.php` — three nullable columns.
- `resources/js/Pages/Auth/TwoFactorChallenge.vue`, `resources/js/Pages/Auth/TwoFactorSetup.vue` — new Sakai/GuestLayout pages.
- Tests: `tests/Unit/{UserTwoFactorTest,TwoFactorServiceTest}.php`, `tests/Feature/{TwoFactorAuthTest,TwoFactorEnforcementTest}.php`.
- Full diff: `git diff HEAD` in `f:\LARAVEL\remunisasi_v2`.

</details>
