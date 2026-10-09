# Design Review — Admin 2FA (TOTP) Wajib — Verifikasi Fix Putaran ke-4

Review terfokus terhadap `.agents/tasks/admin-2fa/design.md` (revisi ke-4). Tujuan review ini **terbatas**: mengonfirmasi bahwa 6 temuan tersisa dari review sebelumnya (`design-review.md` putaran ke-3) kini telah ditangani secara konkret di desain. Tidak membuka ulang keputusan yang sudah selesai, tidak menambah scope baru. Setiap klaim "sudah diverifikasi" di desain diperiksa ulang terhadap sumber aktual repo bila relevan.

---

## Status 6 Fix yang Diminta

### Fix 1 — [HIGH sebelumnya] Guard `setup()` + exemption `EnsureTwoFactor` — DITANGANI ✅

**Di mana:** §`TwoFactorController` method 3 `setup()`; §Middleware Penegakan `EnsureTwoFactor`.

**Verifikasi:** `setup()` kini membuka dengan guard konkret di baris paling atas, **sebelum** secret apa pun dibaca/dibangkitkan:
```php
if ($user === null || ! $user->requiresTwoFactor() || $user->hasConfirmedTwoFactor()) {
    return redirect()->route('dashboard');
}
```
Ini menolak user null, non-admin, dan admin **terkonfirmasi** sebelum `two_factor_secret` disentuh — menutup kebocoran "re-expose active TOTP secret". Sebagai pertahanan berlapis, `EnsureTwoFactor` kini mencegat admin terkonfirmasi yang menuju `two-factor.setup`/`two-factor.setup.confirm` dan me-redirect ke `dashboard` alih-alih meloloskan `$next`:
```php
if ($user->hasConfirmedTwoFactor()) {
    $enrollmentRoutes = ['two-factor.setup', 'two-factor.setup.confirm'];
    if (in_array($request->route()?->getName(), $enrollmentRoutes, true)) {
        return redirect()->route('dashboard');
    }
    return $next($request);
}
```
Guard adalah kode, bukan prosa. Admin terkonfirmasi tidak dapat lagi mencapai `two-factor.setup`. Tertangani.

### Fix 2 — [MED sebelumnya] Mode challenge dipaku + validasi bersyarat — DITANGANI ✅

**Di mana:** §`TwoFactorController` method 2 `verifyChallenge`; §Validasi Input.

**Verifikasi:** `mode` kini `required|in:totp,recovery` (bukan optional/default), field tunggal `code` divalidasi **bersyarat** via `Rule::when`:
```php
'mode' => 'required|in:totp,recovery',
'code' => ['required','string', Rule::when($request->input('mode')==='totp',
    'regex:/^\d{6}$/',
    'regex:/^[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}$/')],
```
Percabangan verifikasi "STRICTLY mengikuti mode tervalidasi — tidak ada inferensi dari field". Frasa "atau deteksi dari field terisi" telah dihapus, dan §Validasi Input mencatat eksplisit "bukan optional/default". Keterikatan field↔mode satu-ke-satu dinyatakan. Tertangani.

### Fix 3 — [MED sebelumnya] `DecryptException` di tiga titik baca secret — DITANGANI ✅

**Di mana:** §`verifyChallenge`, §`setup`, §`confirmSetup`; tabel §Penanganan Error.

**Verifikasi:** ketiga titik baca secret kini punya try/catch `DecryptException` dengan respons per-site yang benar:
- `verifyChallenge` (pre-login): `report($e)` → `forceFill` 3 kolom null + save → `forget` pending state → `redirect()->route('login')->withErrors([...])`. Tepat: user belum `Auth::login`, jadi tidak di-redirect ke setup (yang butuh `auth`).
- `setup` (post-login): try/catch saat membaca draft secret → reset 3 kolom null → `$secret = null` → regenerate draft di method yang sama.
- `confirmSetup` (post-login): try/catch → reset 3 kolom null → `redirect()->route('two-factor.setup')` untuk re-enroll.

Tabel §Penanganan Error memuat **dua baris terpisah** yang membedakan jalur PRE-login (`verifyChallenge` → redirect login) dan POST-login (`setup`/`confirmSetup` → redirect setup). Tertangani.

### Fix 4 — [MED sebelumnya] Throttle pada rute regenerate — DITANGANI ✅

**Di mana:** §Routing; §Rate Limiting.

**Verifikasi:** rute kini:
```php
Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
    ->middleware('throttle:two-factor')
    ->name('two-factor.recovery-codes.regenerate');
```
§Rate Limiting menyatakan limiter `two-factor` diterapkan ke `two-factor.challenge.verify`, `two-factor.setup.confirm`, **dan** `two-factor.recovery-codes.regenerate` — limiter sama (5/menit per akun). Tertangani.

### Fix 5 — [NIT sebelumnya] `InputOtp integerOnly` dikonfirmasi + fallback — DITANGANI & DIVERIFIKASI ULANG ✅

**Di mana:** §Frontend `TwoFactorChallenge.vue`.

**Verifikasi independen:** desain mengklaim prop `integerOnly` diverifikasi di `node_modules/primevue/inputotp/index.d.ts`. Saya baca file tersebut langsung: `InputOtpProps` memang mendeklarasikan
```ts
/** When present, it specifies that an input field is integer-only. @defaultValue false */
integerOnly?: boolean | undefined;
```
Klaim desain **akurat** — `integerOnly` adalah prop valid, bukan atribut DOM yang gagal diam. Desain juga menyatakan `inputmode="numeric"` + fallback `<InputText inputmode="numeric" maxlength="6">`, dan menegaskan pengaman sesungguhnya adalah validasi server `regex:/^\d{6}$/`. Tertangani.

### Fix 6 — [NIT sebelumnya] Mutator normalisasi array kosong → null — DITANGANI ✅

**Di mana:** §Model, mutator `twoFactorRecoveryCodes`.

**Verifikasi:** mutator kini:
```php
set: fn ($value) => empty($value) ? null : Crypt::encryptString(json_encode(array_values($value))),
```
`empty($value)` mencakup `null` **dan** `[]`, jadi konsumsi recovery code terakhir (`$remaining = []`) menyimpan `NULL`, konsisten dengan jalur reset out-of-band. Komentar inline menjelaskan niatnya. Tertangani.

---

## Verified Assumptions (klaim desain yang DIPERIKSA ULANG & BENAR)

1. **Prop `integerOnly` pada `InputOtp` PrimeVue 4.5.5** — Benar. Dibaca langsung dari `node_modules/primevue/inputotp/index.d.ts`: `integerOnly?: boolean | undefined` dengan deskripsi "integer-only". Ini sebelumnya UNVERIFIED (temuan NIT #5 putaran ke-3); kini terkonfirmasi dari sumber.
2. **Guard `setup()` mencegah ekspos ulang secret aktif** — Benar; guard di baris pertama menolak `hasConfirmedTwoFactor()` sebelum `two_factor_secret` dibaca/dibangkitkan.
3. **`EnsureTwoFactor` tidak lagi meloloskan admin terkonfirmasi ke enrollment** — Benar; cek `in_array($request->route()?->getName(), ['two-factor.setup','two-factor.setup.confirm'])` me-redirect ke dashboard.
4. **Tabel §Penanganan Error membedakan PRE-login vs POST-login DecryptException** — Benar; dua baris terpisah dengan respons berbeda.
5. **Rute regenerate memakai `throttle:two-factor`** — Benar; tercantum di diff Routing dan konsisten dengan §Rate Limiting.
6. **Mutator `empty($value) ? null : ...`** — Benar; menormalkan `[]`→null.

## Unverified / Wrong Assumptions

Tidak ada yang relevan dengan 6 fix dalam scope review ini. Satu-satunya item UNVERIFIED pada putaran ke-3 yang menyentuh fix (prop `integerOnly`) kini telah diverifikasi dari sumber dan dipindah ke Verified Assumptions. Risiko eksekusi di luar scope (ketersediaan paket `pragmarx/google2fa-laravel` & `bacon/bacon-qr-code` di Packagist) tetap merupakan risiko runtime terdokumentasi dengan instruksi "STOP & lapor" — bukan cacat desain dan di luar 6 fix yang direview.

---

## Verdict

Keenam fix yang diminta telah ditangani secara konkret di desain:

- Fix 1 (HIGH) — ✅
- Fix 2 (MED) — ✅
- Fix 3 (MED) — ✅
- Fix 4 (MED) — ✅
- Fix 5 (NIT) — ✅ (klaim prop diverifikasi ulang dari sumber)
- Fix 6 (NIT) — ✅

HIGH: 0 | MEDIUM: 0 | NIT: 0 (semua temuan sebelumnya terselesaikan)

Jumlah HIGH+MEDIUM = 0 → **APPROVED**.
