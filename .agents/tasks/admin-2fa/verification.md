# Laporan Verifikasi — 2FA (TOTP) Wajib untuk Admin

Iterasi: **pertama** (tidak ada `review.json`). Diimplementasikan penuh dari `design.md` + `plan.md`.
Dijalankan dari `f:\LARAVEL\remunisasi_v2` (Windows, PowerShell). Perubahan dibiarkan untuk direview — **tidak** di-commit.

## Ringkasan hasil

| Langkah | Status | Catatan |
|---|---|---|
| `composer require pragmarx/google2fa-laravel bacon/bacon-qr-code` | ✅ VERIFIED runtime | Terpasang dari Packagist |
| `php artisan migrate` | ✅ VERIFIED runtime | Kolom 2FA ditambahkan |
| Unit test (service + model) | ✅ VERIFIED runtime | Semua lulus |
| Feature test (login + challenge + enforcement + setup) | ✅ VERIFIED runtime | Semua lulus |
| Suite penuh (regresi) | ✅ VERIFIED runtime | 107 passed (387 assertions) |
| `php artisan route:list` | ✅ VERIFIED runtime | 5 rute 2FA + middleware benar |
| `php artisan migrate:status` | ✅ VERIFIED runtime | Migration `100002` tercatat `[4] Ran` |
| `vendor\bin\pint --dirty` | ✅ VERIFIED runtime | Gaya kode bersih |
| `npm run build` | ✅ VERIFIED runtime | Build Vite sukses, dua halaman baru ter-bundle |
| Enrollment/scan QR + render light/dark | ⏳ TIDAK diverifikasi (manual QA) | Butuh browser + authenticator fisik |

---

## 1. Instalasi paket (VERIFIED)

`composer require pragmarx/google2fa-laravel bacon/bacon-qr-code` berhasil mengunci & memasang:

```
bacon/bacon-qr-code                3.1.1
pragmarx/google2fa                 8.0.3
pragmarx/google2fa-laravel         3.0.1
pragmarx/google2fa-qrcode          3.0.1
dasprid/enum                       1.0.7   (transitif)
paragonie/constant_time_encoding   3.1.3   (transitif)
```

Packagist terjangkau; tidak perlu STOP. (Catatan: `composer require` menulis stderr yang membuat PowerShell melaporkan exit code 1, tetapi lock & install selesai — dikonfirmasi via `composer show`.)

## 2. Migration (VERIFIED)

`php artisan migrate` menjalankan `2026_10_07_100002_add_two_factor_columns_to_users_table` (35.95ms, DONE).
`php artisan migrate:status` menampilkan migration tercatat `[4] Ran`.
Tiga kolom nullable ditambahkan: `two_factor_secret` (text), `two_factor_recovery_codes` (text), `two_factor_confirmed_at` (timestamp).

## 3. Test terfokus (VERIFIED runtime, sqlite :memory:)

Dijalankan `php artisan test`. Semua suite baru + lama lulus. **107 passed (387 assertions)**, 0 gagal.

### Unit — `tests/Unit/TwoFactorServiceTest.php` (7 lulus)
- `verify()` menerima OTP valid yang dibangkitkan `Google2FA->getCurrentOtp($secret)`.
- `verify()` menolak kode salah (`000000`).
- `verify()` input non-6-digit (kosong, 3 digit, huruf, 7 digit) → `false` tanpa throw.
- `verify(null, ...)` → `false`.
- `generateRecoveryCodes()` menghasilkan N (= `config('twofactor.recovery_code_count')`) kode unik berformat `XXXX-XXXX`, tanpa karakter ambigu `0/1/O/I`.
- `buildOtpauthUri()` memuat issuer, label, `secret=`.
- `qrSvg()` mengembalikan string berisi `<svg`.

### Unit/Model — `tests/Unit/UserTwoFactorTest.php` (8 lulus)
- Secret tersimpan **terenkripsi** di kolom mentah (bukan plaintext) dan terbaca plaintext via accessor.
- Secret `null` tetap `null`.
- Recovery codes array roundtrip.
- Array kosong tersimpan `null` (bukan ciphertext `"[]"`).
- `consumeRecoveryCode` single-use: kode valid return true + hilang dari set; kode sama kedua kali return false.
- Normalisasi: `ab12 cd34` (huruf kecil + spasi) dan `AB12CD34` (tanpa hubung) cocok dengan tersimpan `AB12-CD34`.
- `hasConfirmedTwoFactor()` sesuai `two_factor_confirmed_at`.

### Feature — `tests/Feature/TwoFactorAuthTest.php` (10 lulus)
- Admin 2FA-terkonfirmasi + password benar → **TIDAK** login, redirect `two-factor.challenge`, session punya `2fa.pending_user_id`.
- **Anti-bypass first-login**: admin dengan row + `two_factor_confirmed_at` terisi → tetap redirect `two-factor.challenge`.
- Non-admin → login langsung ke dashboard, tanpa pending state.
- Admin unconfirmed → login, lalu request berikutnya ke dashboard di-redirect `two-factor.setup` oleh middleware.
- `verifyChallenge` TOTP valid (OTP via `getCurrentOtp`) → `Auth::check()` true + redirect dashboard + pending dibersihkan.
- `verifyChallenge` TOTP salah → tetap guest + error `code`.
- `verifyChallenge` recovery valid → login + kode terkonsumsi; kode sama kedua kali → gagal + guest.
- Guard secret-null: `two_factor_secret===null` saat pending → pending dibersihkan + redirect `login`, `verify(null,...)` tak dipanggil.
- `challenge()` oleh user terautentikasi penuh → redirect dashboard (bukan login).
- `challenge()` tanpa pending → redirect login.

### Feature — `tests/Feature/TwoFactorEnforcementTest.php` (7 lulus)
- Admin unconfirmed akses rute admin (dashboard) → redirect `two-factor.setup`.
- Admin unconfirmed akses `two-factor.setup` → 200.
- POST `logout` → TIDAK di-redirect ke setup, berhasil logout (menutup risiko loop).
- Admin **terkonfirmasi** membuka `two-factor.setup` → redirect dashboard (tak ekspos secret — HIGH #1).
- `confirmSetup` kode valid → respons Inertia `Auth/TwoFactorSetup` dengan `justConfirmed=true` + `recoveryCodes` (len = config); `two_factor_confirmed_at` terisi.
- GET `two-factor.setup` berikutnya **tidak** memuat prop `recoveryCodes`/`justConfirmed` (hanya `qrSvg`+`secret`).
- `confirmSetup` kode salah → error `code`, `two_factor_confirmed_at` tetap null.

### Regresi — `tests/Feature/LoginTest.php` (5 lulus)
Jalur SIKEP non-admin tidak berubah: pesan generik, QueryException, penolakan akun tanpa peran, validasi field tetap seperti sebelumnya.

### Penyesuaian test lama (bukan perubahan perilaku user)
Middleware `EnsureTwoFactor` kini menegakkan 2FA untuk **semua** admin. Beberapa test lama membuat admin **tanpa** 2FA terkonfirmasi sehingga sebelumnya langsung mengakses halaman admin; kini mereka benar di-redirect ke setup. Fixture admin pada test berikut disesuaikan agar mewakili admin yang **sudah enroll** (`two_factor_confirmed_at => now()`) — ini menyelaraskan test dengan requirement 2FA-wajib, bukan mengubah apa yang dilihat pengguna:
- `AdminManagementTest`, `IncentiveConfigTest`, `IndividualTargetTest`, `ApproverMappingTest` (hanya saat role admin), `AttachmentAccessTest` (test admin).

Test katimker/kabid tidak diubah (non-admin tidak terdampak `EnsureTwoFactor`).

## 4. Route list (VERIFIED)

`php artisan route:list --name=two-factor -v` menampilkan kelima rute dengan middleware benar:

| Rute | Name | Middleware |
|---|---|---|
| `GET two-factor/challenge` | `two-factor.challenge` | — (guard pending di controller) |
| `POST two-factor/challenge` | `two-factor.challenge.verify` | `throttle:two-factor` |
| `GET two-factor/setup` | `two-factor.setup` | `auth`, `two-factor` |
| `POST two-factor/setup` | `two-factor.setup.confirm` | `auth`, `two-factor`, `throttle:two-factor` |
| `POST two-factor/recovery-codes` | `two-factor.recovery-codes.regenerate` | `auth`, `two-factor`, `throttle:two-factor` |

Rute challenge berada **di luar** grup `guest`/`auth` (benar: pending-2FA bukan login penuh). Grup `auth` kini `['auth','two-factor']`. Tidak ada error "Rate limiter [two-factor] is not defined".

## 5. Build frontend (VERIFIED)

`npm run build` sukses (`✓ built in 892ms`). Bundle dihasilkan untuk kedua halaman baru:
- `public/build/assets/TwoFactorChallenge-*.js`
- `public/build/assets/TwoFactorSetup-*.js`

Komponen PrimeVue (`InputOtp`, `InputText`, `Button`, `Message`) ter-resolve otomatis tanpa import manual.

## 6. Pint (VERIFIED)

`vendor\bin\pint --dirty` → "FIXED ... style issues fixed", lalu suite tetap 107 passed setelah reformat.

---

## Yang TIDAK diverifikasi di runtime (QA manual pasca-merge)

Berikut **tidak** dapat diuji headless dan dibiarkan untuk QA browser manual:
- Enrollment end-to-end nyata: scan QR dengan Google Authenticator/Authy fisik, lalu konfirmasi.
- Render visual kedua halaman (`TwoFactorChallenge.vue`, `TwoFactorSetup.vue`) di mode **light** dan **dark** (`.app-dark`), termasuk tombol tema `<FloatingConfigurator />`.
- Interaksi UI: toggle InputOtp ↔ input recovery code, salin kunci, salin semua / unduh `.txt` recovery codes, tombol "Keluar" dan "Selesai, lanjut ke dashboard".
- Perilaku 429 di browser saat melebihi 5 percobaan/menit (limiter terdaftar & terpasang; respons otomatis Laravel).

Semua jalur logika di belakangnya diuji otomatis (lihat bagian 3); yang tersisa murni presentasi/perangkat eksternal.
