# Implementation Plan — 2FA (TOTP) Wajib untuk Admin

Rencana ini mengikuti `.agents/tasks/admin-2fa/design.md` (APPROVED). Semua perintah dijalankan dari `f:\LARAVEL\remunisasi_v2` (PowerShell). Urutan item mengikuti dependensi build: paket → migrasi → config → model → service → controller login → controller 2FA → middleware → routing → rate limiter → shared props → frontend → verifikasi penuh.

Konteks yang sudah diverifikasi dari kode:
- Stack: Laravel 13.8 (PHP ^8.3) + Inertia 3 + Vue 3 + PrimeVue 4.5.5 (auto-import via `@primevue/auto-import-resolver`), Tailwind v4, Vite 8.
- Test: PHPUnit 12, `DB_CONNECTION=sqlite` `:memory:` (lihat `phpunit.xml`), `SESSION_DRIVER=array`, `CACHE_STORE=array`. Perintah test: `php artisan test`. Pola test di `tests/Feature/LoginTest.php` mem-`mock(SikepAuthService::class)` + `mock(RoleResolver::class)` dengan `RefreshDatabase`.
- Migration terakhir: `database/migrations/2026_10_07_100001_add_token_pegawai_to_users_table.php` → file baru pakai `2026_10_07_100002`.
- `UserFactory` punya state `admin()` (`is_admin => true`).
- `routes/web.php`: grup `guest` (login) dan grup `auth` (semua rute app, termasuk `logout`). Alias middleware di `bootstrap/app.php` saat ini hanya `'role'`.
- `AppServiceProvider::boot()` kosong; tidak ada `RateLimiter::for` di mana pun.
- `HandleInertiaRequests::share()` mengekspos `auth.user` dengan field turunan + 4 key flash hardcoded.
- `Login.vue` memakai `defineOptions({ layout: GuestLayout })` dan me-mount `<FloatingConfigurator />` di template-nya sendiri.

Catatan: nama file migration di prompt (`..._create_two_factor_columns_...`) dan di design (`..._add_two_factor_columns_...`) berbeda. Mengikuti design yang APPROVED: gunakan `2026_10_07_100002_add_two_factor_columns_to_users_table.php` (operasi `Schema::table`, bukan `create`). Asumsi dicatat agar implementer tidak ragu.

---

- [ ] 1. Instal dependensi TOTP + QR via Composer.
      Jalankan `composer require pragmarx/google2fa-laravel` lalu `composer require bacon/bacon-qr-code`. Jika Packagist tidak terjangkau, STOP dan laporkan — paket ini tidak boleh di-stub.
      Files: `composer.json`, `composer.lock` (dimodifikasi oleh Composer; jangan edit manual).
      Verify: `composer show pragmarx/google2fa-laravel` dan `composer show bacon/bacon-qr-code` keduanya menampilkan versi terpasang; `php artisan about` berjalan tanpa error paket.

- [ ] 2. Buat migration kolom 2FA pada tabel `users`.
      Tiga kolom nullable: `two_factor_secret` (text), `two_factor_recovery_codes` (text), `two_factor_confirmed_at` (timestamp), masing-masing `->after(...)` berurutan setelah `token_pegawai` (kosmetik, diabaikan diam di SQLite — lihat §Migration design). `down()` men-drop ketiganya. Timestamp `100002` menjamin dijalankan sesudah `100001`.
      Files: `database/migrations/2026_10_07_100002_add_two_factor_columns_to_users_table.php`
      Verify: `php artisan migrate` sukses pada DB dev; `php artisan migrate:fresh --env=testing` (sqlite :memory:) jalan tanpa error. Konfirmasi via `php artisan db:show` atau test item 5 yang membaca/menulis kolom.

- [ ] 3. Buat file konfigurasi `config/twofactor.php`.
      Return array dengan `issuer` (`env('TWO_FACTOR_ISSUER', 'Remunisasi RSUD Bangil')`), `recovery_code_count` (8), `window` (1). Jangan menumpang di `config/sikep.php`.
      Files: `config/twofactor.php`
      Verify: `php artisan config:clear` lalu `php artisan tinker --execute="echo config('twofactor.issuer');"` mencetak issuer; `config('twofactor.recovery_code_count')` = 8.

- [ ] 4. Tambah kolom 2FA + accessor/mutator + helper pada model `User`.
      Tambahkan ketiga kolom ke atribut `#[Fillable]`; tambahkan `two_factor_secret` dan `two_factor_recovery_codes` ke `#[Hidden]`; tambahkan cast `'two_factor_confirmed_at' => 'datetime'`. Tambahkan accessor/mutator terenkripsi `twoFactorSecret()` (get decrypt / set encrypt, null→null) dan `twoFactorRecoveryCodes()` (get: null→`[]` else `json_decode(decrypt)`, set: `empty→null` else `encrypt(json_encode(array_values))`), method `hasConfirmedTwoFactor()`, `requiresTwoFactor()` (hanya dipakai middleware, memanggil `roles()['is_admin']`), dan `consumeRecoveryCode(string $code)` (single-use, normalisasi strip non-alnum + strtoupper di kedua sisi, `hash_equals`, simpan sisa format asli, `save()`). Import `Illuminate\Database\Eloquent\Casts\Attribute` dan `Illuminate\Support\Facades\Crypt`. JANGAN mengubah `syncFromSikep()` maupun `roles()`.
      Files: `app/Models/User.php`
      Verify: item 5 (unit test model) lulus. Sementara: `php artisan test --filter=UserTwoFactor` setelah item 5 ada.

- [ ] 5. Tulis unit test model untuk enkripsi + `consumeRecoveryCode`.
      Cakup: secret di-set lewat mutator tersimpan terenkripsi (bukan plaintext di kolom mentah) dan terbaca kembali plaintext; recovery codes array di-roundtrip; array kosong tersimpan `null`; `consumeRecoveryCode` dengan kode valid return true + kode hilang dari set, kode sama kedua kali return false; normalisasi (`AB12CD34`, huruf kecil, dengan spasi) cocok dengan tersimpan `AB12-CD34`; `hasConfirmedTwoFactor()` sesuai `two_factor_confirmed_at`. Pakai `RefreshDatabase`, `User::factory()`.
      Files: `tests/Unit/UserTwoFactorTest.php`
      Verify: `php artisan test --filter=UserTwoFactorTest` — semua lulus.

- [ ] 6. Buat `TwoFactorService`.
      Kelas `app/Services/TwoFactor/TwoFactorService.php` meng-inject `PragmaRX\Google2FA\Google2FA`. Method: `generateSecret()`, `buildOtpauthUri($secret, $accountLabel)` (issuer dari `config('twofactor.issuer')`, SHA1/6/30), `qrSvg($otpauthUri)` (BaconQrCode `ImageRenderer` + `SvgImageBackEnd` + `RendererStyle` + `Writer`, kembalikan markup `<svg>`), `verify($secret, $code)` (`verifyKey` dengan `config('twofactor.window')`; guard defensif non-6-digit → `false` diam, tanpa melempar), `generateRecoveryCodes()` (`recovery_code_count` kode unik, alfabet `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` via `random_int`, format `XXXX-XXXX`, dedup).
      Files: `app/Services/TwoFactor/TwoFactorService.php`
      Verify: item 7 (unit test service) lulus.

- [ ] 7. Tulis unit test untuk `TwoFactorService`.
      Cakup: `verify()` menerima OTP valid yang dibangkitkan via `Google2FA->getCurrentOtp($secret)` dan menolak kode salah; `verify()` dengan input non-6-digit return `false` tanpa throw; `generateRecoveryCodes()` menghasilkan N kode unik berformat `XXXX-XXXX` tanpa karakter ambigu `0/1/O/I`; `buildOtpauthUri()` memuat issuer + label + `secret=`; `qrSvg()` mengembalikan string berisi `<svg`.
      Files: `tests/Unit/TwoFactorServiceTest.php`
      Verify: `php artisan test --filter=TwoFactorServiceTest` — semua lulus.

- [ ] 8. Sisipkan cabang 2FA ke `LoginController::store()` dan bersihkan state di `destroy()`.
      Di `store()`, SETELAH `$user = User::syncFromSikep($sikepUser)` dan SEBELUM `Auth::login(...)`, tambahkan: `$isAdmin = $roles['is_admin'];` lalu `if ($isAdmin && $user->hasConfirmedTwoFactor()) { session put '2fa.pending_user_id'=$user->id, '2fa.remember'=boolean remember; return redirect()->route('two-factor.challenge'); }`. Jalur `Auth::login` + `regenerate` + `redirect()->intended` tetap untuk kasus lain. WAJIB pakai `$roles['is_admin']` yang sudah di-resolve; JANGAN panggil `$user->requiresTwoFactor()` di sini (invarian urutan). Blok existing (QueryException, pesan generik, penolakan akun tanpa peran) TIDAK diubah. Di `destroy()`, tambahkan `$request->session()->forget(['2fa.pending_user_id', '2fa.remember']);` sebelum `invalidate()`.
      Files: `app/Http/Controllers/Auth/LoginController.php`
      Verify: `php artisan test --filter=LoginTest` tetap lulus (regresi non-admin tidak berubah); cabang baru diuji di item 13.

- [ ] 9. Buat `TwoFactorController` dengan lima method.
      Kelas `app/Http/Controllers/Auth/TwoFactorController.php`, inject `TwoFactorService`. Implementasikan sesuai §TwoFactorController design:
      `challenge()` (GET): jika `Auth::check()` → redirect dashboard; jika `session('2fa.pending_user_id')` kosong → redirect login; else `Inertia::render('Auth/TwoFactorChallenge')`.
      `verifyChallenge()` (POST): guard pending; `User::find(pending)` null → forget+login; guard integritas (`two_factor_secret===null` atau `!hasConfirmedTwoFactor()`) → forget+login; validasi `mode` (`required|in:totp,recovery`) + `code` bersyarat (`Rule::when` TOTP `/^\d{6}$/` else recovery `/^[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}$/`); verifikasi dalam try/catch `DecryptException` (PRE-login: report, forceFill tiga kolom null+save, forget pending, redirect login withErrors generik); cabang strict by `$data['mode']`; valid → `Auth::login` dengan `session('2fa.remember')` + forget + regenerate + redirect intended dashboard; invalid → `ValidationException` key `code` "Kode verifikasi tidak valid.".
      `setup()` (GET): guard baris pertama `if ($user===null || !$user->requiresTwoFactor() || $user->hasConfirmedTwoFactor()) return redirect dashboard;` lalu baca draft secret dalam try/catch `DecryptException` (POST-login: reset null+save, `$secret=null`); jika null generate+save; build URI + qrSvg; `Inertia::render('Auth/TwoFactorSetup', ['qrSvg'=>..., 'secret'=>...])`.
      `confirmSetup()` (POST): validasi `code` `required|string|regex:/^\d{6}$/`; verify dalam try/catch `DecryptException` (POST-login: reset null+save, redirect `two-factor.setup`); false → `ValidationException` key `code`; sukses → generate recovery codes, set `two_factor_recovery_codes` + `two_factor_confirmed_at=now()` + save, `Inertia::render('Auth/TwoFactorSetup', ['recoveryCodes'=>..., 'justConfirmed'=>true])`.
      `regenerateRecoveryCodes()` (POST): generate baru + simpan + `Inertia::render` pola sama (`recoveryCodes`, `justConfirmed=true`).
      Import `Illuminate\Validation\Rule`, `Illuminate\Contracts\Encryption\DecryptException`, `Inertia\Inertia`, `Illuminate\Support\Facades\Auth`.
      Files: `app/Http/Controllers/Auth/TwoFactorController.php`
      Verify: aplikasi boot (`php artisan route:list` setelah item 11 menampilkan handler tanpa error); logika diuji di item 13–14.

- [ ] 10. Buat middleware `EnsureTwoFactor` dan daftarkan alias `two-factor`.
      Buat `app/Http/Middleware/EnsureTwoFactor.php` mengikuti gaya `EnsureRole`: user null → `$next`; `!requiresTwoFactor()` → `$next`; `hasConfirmedTwoFactor()` → jika route name ∈ `['two-factor.setup','two-factor.setup.confirm']` redirect dashboard, else `$next`; admin unconfirmed → jika route name ∈ `['two-factor.setup','two-factor.setup.confirm','logout']` `$next`, else redirect `two-factor.setup`. Daftarkan alias di `bootstrap/app.php`: tambahkan `'two-factor' => \App\Http\Middleware\EnsureTwoFactor::class` ke array `$middleware->alias([...])` (web `append` TIDAK diubah).
      Files: `app/Http/Middleware/EnsureTwoFactor.php`, `bootstrap/app.php`
      Verify: `php artisan route:list` berjalan tanpa error "middleware not found"; penegakan diuji di item 14.

- [ ] 11. Tambahkan rute 2FA ke `routes/web.php`.
      Di luar grup `guest` dan `auth`: `GET /two-factor/challenge` → `challenge` (name `two-factor.challenge`); `POST /two-factor/challenge` → `verifyChallenge` dengan `throttle:two-factor` (name `two-factor.challenge.verify`). Ubah grup `auth` dari `Route::middleware('auth')` menjadi `Route::middleware(['auth', 'two-factor'])` (definisi rute existing di dalamnya TIDAK berubah), lalu tambahkan di dalam grup: `GET /two-factor/setup` → `setup` (name `two-factor.setup`); `POST /two-factor/setup` → `confirmSetup` + `throttle:two-factor` (name `two-factor.setup.confirm`); `POST /two-factor/recovery-codes` → `regenerateRecoveryCodes` + `throttle:two-factor` (name `two-factor.recovery-codes.regenerate`). Import `App\Http\Controllers\Auth\TwoFactorController`.
      Files: `routes/web.php`
      Verify: `php artisan route:list` menampilkan kelima nama rute (`two-factor.challenge`, `two-factor.challenge.verify`, `two-factor.setup`, `two-factor.setup.confirm`, `two-factor.recovery-codes.regenerate`); grup `auth` menunjukkan middleware `two-factor` pada rute terautentikasi.

- [ ] 12. Daftarkan rate limiter `two-factor` di `AppServiceProvider::boot()`.
      Isi `boot()` dengan `RateLimiter::for('two-factor', fn (Request $request) => [Limit::perMinute(5)->by((string)($request->session()->get('2fa.pending_user_id') ?? optional($request->user())->id ?? $request->ip()))])`. Tambah import `Illuminate\Cache\RateLimiting\Limit`, `Illuminate\Http\Request`, `Illuminate\Support\Facades\RateLimiter`.
      Files: `app/Providers/AppServiceProvider.php`
      Verify: `php artisan route:list` tidak melempar "Rate limiter [two-factor] is not defined" untuk rute ber-`throttle:two-factor`; perilaku 429 opsional diuji di item 14.

- [ ] 13. Tulis feature test untuk `LoginController` + `verifyChallenge`.
      Ikuti pola `tests/Feature/LoginTest.php` (mock `SikepAuthService` + `RoleResolver`, `RefreshDatabase`). Cakup: admin 2FA-terkonfirmasi + password benar → TIDAK login, redirect `two-factor.challenge`, session punya `2fa.pending_user_id`; admin dengan row user BARU dibuat pada login itu + `two_factor_confirmed_at` terisi → tetap redirect `two-factor.challenge` (anti-bypass first-login); non-admin → login langsung dashboard; admin unconfirmed → login tapi diarahkan ke setup (lewat middleware); `verifyChallenge` TOTP valid → `Auth::check()` true + redirect dashboard; TOTP salah → tetap guest + error `code`; recovery code valid → login + kode terkonsumsi (kode sama kedua kali gagal); guard secret-null → pending dibersihkan + redirect login tanpa error dari `verify(null,...)`; `challenge()` oleh user terautentikasi penuh → redirect dashboard.
      Files: `tests/Feature/TwoFactorAuthTest.php`
      Verify: `php artisan test --filter=TwoFactorAuthTest` — semua lulus.

- [ ] 14. Tulis feature test untuk `EnsureTwoFactor` + `setup`/`confirmSetup`.
      Cakup: admin unconfirmed akses rute admin → redirect `two-factor.setup`; akses `two-factor.setup` → lolos (200); POST `logout` → TIDAK di-redirect ke setup, berhasil logout; admin terkonfirmasi membuka `two-factor.setup` → redirect dashboard (tak ekspos secret); `confirmSetup` kode valid → respons prop `recoveryCodes` (len = `config('twofactor.recovery_code_count')`) + `justConfirmed=true`, `two_factor_confirmed_at` terisi; GET `two-factor.setup` berikutnya tidak memuat `recoveryCodes`. Pakai `actingAs` + factory `admin()`; mock `RoleResolver` agar `is_admin=true` untuk sikep_user_id user uji.
      Files: `tests/Feature/TwoFactorEnforcementTest.php`
      Verify: `php artisan test --filter=TwoFactorEnforcementTest` — semua lulus.

- [ ] 15. Tambahkan dua boolean non-sensitif ke shared Inertia props.
      Di `HandleInertiaRequests::share()` closure `auth.user`, tambahkan `'two_factor_enabled' => $u->hasConfirmedTwoFactor()` dan `'needs_two_factor_setup' => $roles['is_admin'] && ! $u->hasConfirmedTwoFactor()`. JANGAN mengirim secret/recovery codes. Jangan menambah key flash baru.
      Files: `app/Http/Middleware/HandleInertiaRequests.php`
      Verify: `php artisan test` (suite feature yang merender halaman auth) tetap lulus; props muncul tanpa membocorkan kolom sensitif (dicek via test item 13/14 bila mengakses props).

- [ ] 16. Buat halaman `TwoFactorChallenge.vue`.
      `resources/js/Pages/Auth/TwoFactorChallenge.vue`: `defineOptions({ layout: GuestLayout })`, import `GuestLayout` + `FloatingConfigurator`, mount `<FloatingConfigurator />` di template. `useForm({ mode: 'totp', code: '' })`. `<InputOtp :length="6" integerOnly v-model="form.code" />` untuk TOTP; toggle "Gunakan kode pemulihan" menukar `form.mode='recovery'` + tampilkan `<InputText>`. Submit `form.post(route('two-factor.challenge.verify'))`; `<Button label="Verifikasi" :loading="form.processing" class="w-full">`. Error `form.errors.code` via `<small class="text-red-500">`; pesan umum/429 via `<Message severity="error">`. Teks Indonesia; token tema + `dark:` seperti `Login.vue`. Komponen PrimeVue auto-import (tanpa import manual).
      Files: `resources/js/Pages/Auth/TwoFactorChallenge.vue`
      Verify: `npm run build` sukses (item 18 memverifikasi build penuh).

- [ ] 17. Buat halaman `TwoFactorSetup.vue`.
      `resources/js/Pages/Auth/TwoFactorSetup.vue`: `defineOptions({ layout: GuestLayout })`, mount `<FloatingConfigurator />` sendiri. `defineProps({ qrSvg:{type:String,default:null}, secret:{type:String,default:null}, recoveryCodes:{type:Array,default:()=>[]}, justConfirmed:{type:Boolean,default:false} })`. Computed fase saling-eksklusif: `faseRecovery = justConfirmed===true`; `faseEnrollment = !justConfirmed && !!qrSvg`. Fase enrollment (`v-if="faseEnrollment"`): QR `v-html="qrSvg"` (SVG dari server, aman), kunci manual `secret` + tombol salin (toast), `<InputOtp :length="6" v-model="form.code" />` + `<Button label="Aktifkan 2FA" :loading>` → `form.post(route('two-factor.setup.confirm'))`. Fase recovery (`v-if="faseRecovery"`): daftar `recoveryCodes` grid mono, `<Message severity="warn">` peringatan simpan sekarang, tombol Salin semua + Unduh .txt (blob klien), tombol "Selesai, lanjut ke dashboard" `router.visit(route('dashboard'))`. Tombol "Keluar" `router.post(route('logout'))` di atas kartu. Teks Indonesia, token tema + `dark:`. Jangan membaca `recoveryCodes` dari `usePage().props.flash`.
      Files: `resources/js/Pages/Auth/TwoFactorSetup.vue`
      Verify: `npm run build` sukses (item 18).

- [ ] 18. Verifikasi penuh (build + test + routes).
      Jalankan verifikasi lengkap sesuai §Rencana Verifikasi design.
      Files: — (tidak ada perubahan file; jika ada kegagalan, perbaiki item terkait lalu ulang)
      Verify (semua harus lolos):
      - `vendor\bin\pint --dirty` (atau `php artisan pint` jika tersedia) — gaya kode bersih.
      - `php artisan test` — seluruh suite (lama + baru: `UserTwoFactorTest`, `TwoFactorServiceTest`, `TwoFactorAuthTest`, `TwoFactorEnforcementTest`) lulus; `LoginTest` tidak regresi.
      - `php artisan route:list` — kelima rute 2FA muncul dengan middleware benar (`throttle:two-factor` pada verify/confirm/regenerate; `two-factor` pada grup auth).
      - `php artisan migrate:status` — migration `100002` tercatat.
      - `npm run build` — build Vite sukses dengan dua halaman Vue baru (PrimeVue `InputOtp` dll auto-resolve).

---

## Catatan untuk implementer

- QA browser manual (tidak headless) di luar cakupan item otomatis: scan QR dengan authenticator sungguhan + enrollment end-to-end, render light/dark kedua halaman, copy/download recovery codes, alih InputOtp ↔ input recovery. Catat sebagai verifikasi manual pasca-merge.
- Jangan menyentuh jalur SIKEP non-admin: `SikepAuthService`, pesan generik, penanganan `QueryException`, penolakan akun tanpa peran tetap byte-for-byte secara perilaku.
- Semua penulisan nilai sensitif ke kolom 2FA hanya lewat mutator model (enkripsi at-rest); jangan menulis plaintext langsung ke kolom.
- Jika sebuah pendekatan gagal dua kali, diagnosis akar masalah sebelum menambal; jangan mengubah perilaku yang terlihat user tanpa konfirmasi.
