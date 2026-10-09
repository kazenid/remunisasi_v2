# Role manual: lepas penentuan peran dari SIKEP ke kolom lokal `users.role`+`aktif`

Perubahan ini memindahkan sumber kebenaran peran (Katimker/Kabid/Admin) dari jabatan lapangan SIKEP ke kolom lokal `users.role` + `users.aktif` yang ditetapkan manual oleh Admin, karena jabatan "kepala tim" di SIKEP ternyata tim kerja unit, bukan Katimker di hierarki remunisasi. SIKEP tetap jadi direktori kepegawaian read-only dan sumber verifikasi password; ia tidak lagi menentukan peran. Admin menarik pegawai lewat menu Kepegawaian dan menetapkan role; hanya user `aktif=true` ber-role sah yang boleh masuk. Keputusan pemilik mengunci **Opsi A**: backfill non-admin lama hanya menyetel `role=null` dan membiarkan `aktif=true`.

Watch for: tidak ada temuan yang memblokir. Jalur role sudah lepas penuh dari SIKEP dan fail-closed konsisten di tiga titik (gerbang login, middleware sesi, guard pending-2FA); backfill Opsi A terverifikasi benar di kode; invarian `is_admin⇔role=admin` terjaga di satu jalur `setRole()`; anti-lockout UI lengkap; audit memakai nama aksi `pengguna.*` sesuai design.

**Verdict**: APPROVED

## High-level view

Penentuan peran kini bersumber tunggal dari `RoleResolver` yang membaca `users.role`+`aktif` lokal dan fail-closed (user tak ada / `aktif=false` / `role=null` → peran kosong), lepas dari `SikepJabatanLapanganUser`. Kontrak keluaran `{roles,is_katimker,is_kabid,is_admin}` tidak berubah sehingga semua konsumen lama tetap bekerja.

Penegakan "akun masih berhak" bertumpu pada tiga titik yang saling melengkapi dan memakai satu teks pesan yang sama: gerbang login tiga-cabang di `LoginController` (pesan Indonesia berbeda untuk belum-ditarik / nonaktif / tanpa-role), middleware `EnsureActiveRole` untuk seluruh request terautentikasi grup `auth`, dan guard fail-closed di `TwoFactorController::verifyChallenge()` yang menutup jendela pending-2FA (route challenge berada di luar grup auth). Urutan middleware `auth → active-role → two-factor → role:` memastikan user nonaktif di-logout sebelum logika 2FA/otorisasi berjalan.

Menu Kepegawaian memisahkan tiga aksi: `tarik()` (buat record baru, tetapkan role + `aktif=true`, tolak record yang sudah ada), `ubahRole()` (tidak menyentuh `aktif`), dan `toggleAktif()` (tidak menghapus record). Dua guard anti-lockout melindungi admin: tidak bisa menurunkan/menonaktifkan diri sendiri, dan tidak bisa menyisakan nol admin aktif secara global. Semua tercatat via `AuditLogger` dengan aksi `pengguna.tarik`/`pengguna.role`/`pengguna.aktif`.

Invarian `role='admin' ⇔ is_admin=true` dijaga di satu jalur tulis `User::setRole()`, dengan dua pengecualian terdokumentasi (backfill migrasi dan factory state) yang menulis kedua kolom berpasangan. `ApproverMapping` memakai role lokal untuk kandidat Katimker/Kabid dengan field aditif `kabid_valid` untuk menandai pemetaan historis yang menyimpang, dan pemetaan field error (Temuan 4) sudah benar. Konstanta `JABATAN_*` dan helper `isKatimker()/isKabid()` di `SikepUser` dipertahankan untuk laporan, lepas dari jalur role.

<details>
<summary>Issues (0)</summary>

Tidak ada temuan yang memblokir maupun non-blocking yang perlu ditindaklanjuti. Seluruh poin verifikasi yang diminta terpenuhi di kode.

</details>

<details>
<summary>Details</summary>

### Resolusi peran lepas dari SIKEP dan fail-closed

`RoleResolver::resolve()` (confirmed) kini mengkueri `User::where('sikep_user_id', …)->first(['role','aktif'])` dan mengembalikan peran kosong bila user tidak ada, `aktif === false`, atau `role === null`. `SikepJabatanLapanganUser` tidak lagi dirujuk di jalur ini. Query dibungkus `try/catch QueryException` dengan `report($e)` lalu return kosong — fail-closed sebagai pertahanan berlapis, bukan fail-open. Kontrak `{roles,is_katimker,is_kabid,is_admin}` dipertahankan, jadi `HandleInertiaRequests`, `EnsureRole`, `requiresTwoFactor()`, dan policies tetap bekerja tanpa perubahan kontrak nilai.

### Gerbang login tiga-cabang dengan pesan Indonesia berbeda

`LoginController::store()` (confirmed) memverifikasi password ke SIKEP dulu (`QueryException` → "Integrasi SIKEP tidak tersedia saat ini."; `null` → "Kredensial yang diberikan tidak cocok."), lalu mengambil record lokal eksplisit dan mengevaluasi berurutan: `null` → "Akun Anda belum terdaftar/diaktifkan di aplikasi."; `aktif===false` → "Akun Anda dinonaktifkan."; `role===null` → "Akun Anda belum memiliki peran." Ketiga pesan dibedakan HANYA oleh cek eksplisit ini, bukan oleh resolver, sehingga tidak ada kolaps menjadi satu pesan generik. Pada cabang belum-ditarik, `syncFromSikep()` sengaja tidak dipanggil sehingga tak ada record otomatis (`assertDatabaseCount('users',0)` berlaku). Cabang 2FA memakai `$roles['is_admin']` dari resolve tunggal (bukan `requiresTwoFactor()` yang me-resolve ulang), dengan jaring pengaman `roles===[]` → pesan tanpa-peran.

### Guard pending-2FA menutup jendela "dinonaktifkan di tengah"

`TwoFactorController::verifyChallenge()` (confirmed) meng-inject `RoleResolver` dan, setelah kode terverifikasi (`$ok === true`) tetapi SEBELUM `Auth::login()`, memanggil `resolve($user->sikep_user_id)`; bila `roles === []` ia membersihkan `2fa.pending_user_id`/`2fa.remember` dan redirect ke login dengan `EnsureActiveRole::MESSAGE`. Penempatan setelah verifikasi kode berarti penebak kode yang salah tetap mendapat "Kode verifikasi tidak valid." lebih dulu (tidak membocorkan status akun), dan admin yang dinonaktifkan selama pending-2FA tidak pernah mendapat sesi login. Ini menutup satu-satunya jalur login yang menembus sesi tanpa melewati grup auth, karena route `two-factor.challenge[.verify]` memang berada di luar grup `['auth','active-role','two-factor']`.

### Penegakan sesi berjalan dan urutan middleware

`EnsureActiveRole` (confirmed) men-logout + `invalidate()` + `regenerateToken()` lalu `redirect()->withErrors(['login' => self::MESSAGE])` ketika `$user->roles()['roles'] === []`, dengan daftar `EXCEPT` (logout + jalur 2FA) agar user nonaktif tetap bisa keluar. `EnsureRole` (confirmed) menyempit jadi otorisasi per-route murni (`abort(403)` untuk peran tak cocok), karena kasus logout `roles=[]` sudah ditangani lebih dulu. `routes/web.php` memasang grup `['auth','active-role','two-factor']` dan `bootstrap/app.php` mendaftarkan alias `active-role => EnsureActiveRole`. Urutan `auth → active-role → two-factor → role:` sesuai design: dashboard `/` (tanpa `role:`) ikut tercakup, dan user nonaktif dibuang sebelum `EnsureTwoFactor` sempat mengevaluasi `requiresTwoFactor()`.

### Menu Kepegawaian: tarik / ubahRole / toggleAktif dengan anti-lockout dan audit

`UserController` (confirmed) memisahkan jalur tulis dengan jelas. `tarik()` menolak record yang sudah ada (`ValidationException` pada field `sikep_user_id`), mencari `SikepUser` aktif, lalu `syncFromSikep` + `setRole($role)` + `aktif=true` + `save`, dengan `role` divalidasi `Rule::in(User::ROLES)`. `ubahRole()` memvalidasi role wajib dalam domain, menolak penurunan peran admin diri sendiri, menolak perubahan yang menyisakan nol admin aktif, dan sama sekali tidak menyentuh `aktif`. `toggleAktif()` menolak nonaktifkan diri sendiri dan penonaktifan yang menyisakan nol admin aktif, serta tidak menghapus record. Guard nol-admin dihitung lewat `akanMenyisakanNolAdmin()` yang menghitung admin aktif LAIN (`id != $user->id`) dan hanya memblokir bila user ini satu-satunya admin aktif yang berhenti jadi admin aktif. Audit memakai aksi `pengguna.tarik` / `pengguna.role` / `pengguna.aktif` dengan `dari`/`ke` dan `target_sikep_user_id` — sesuai §6 design, dan tidak ada sisa `role.update` atau `setAdmin()`.

### Backfill Opsi A

Migrasi `2026_10_08_100001_add_role_and_aktif_to_users_table` (confirmed) menambah `role` (string(20) nullable) dan `aktif` (boolean default true). Backfill menyetel admin lama → `role='admin', aktif=true`, dan non-admin lama → **hanya** `role=null` (tidak menyentuh `aktif`, tetap default `true`). Ini persis Opsi A: login non-admin lama tetap tertutup karena `role=null`, bukan karena dinonaktifkan. `down()` hanya `dropColumn(['role','aktif'])` tanpa menyentuh `is_admin`. Design.md §1 masih menuliskan varian `aktif=false`, tetapi plan.md dan kode sudah menerapkan Opsi A sebagai yang mengikat; `BackfillBootstrapAdminTest` menegaskan non-admin lama tetap `aktif=true`.

### Invarian `is_admin ⇔ role=admin` di satu jalur

`User::setRole(?string $role)` (confirmed) menyetel `role` dan `is_admin = ($role === ROLE_ADMIN)` tanpa `save()`. `syncFromSikep()` tidak menyentuh `role`/`aktif`/`is_admin`. Semua pemanggil tulis role yang hidup — `tarik()`, `ubahRole()`, `SetAdmin` — melewati `setRole()`; backfill migrasi dan `UserFactory` state menulis kedua kolom berpasangan sebagai dua pengecualian terdokumentasi. `SetAdmin` (confirmed) tanpa `--off` → `setRole(ROLE_ADMIN)` + `aktif=true`; `--off` → `setRole(null)` tanpa mengubah `aktif`; signature `app:set-admin {login} {--off}` dipertahankan.

### ApproverMapping berbasis role lokal dan pemetaan field error

`ApproverMappingService` (confirmed) menyusun kandidat Katimker/Kabid dari `User::where('role',…)->where('aktif',true)`, bukan dari `SikepJabatanLapanganUser`. `listKatimker()` menambah field aditif `kabid_valid` yang menandai apakah kabid pemetaan masih ber-role `kabid` dan aktif, sambil tetap menampilkan `kabid_nama` historis; `Admin/ApproverMapping.vue` merender `Tag` "bukan Kabid aktif" (severity warn) saat `kabid_valid === false`. `assign()` menolak target non-Kabid-aktif / non-Katimker-aktif dengan pesan "Pengguna bukan Katimker aktif." / "Target penilai bukan Kabid aktif." Pemetaan field error di `ApproverMappingController::store()` (Temuan 4) benar: pesan yang mengandung "Katimker" → field `katimker_sikep_user_id`, selainnya → `kabid_sikep_user_id`; kedua pesan konsisten dengan pencocokan substring itu. Kunci `sikep_user_id` dan struktur tabel `ApproverMapping` tidak dirombak.

### SikepUser dipertahankan untuk laporan

Konstanta `JABATAN_KEPALA_TIM`/`JABATAN_KABID` dan helper `isKatimker()/isKabid()` di `SikepUser` (confirmed) tetap ada dan masih mengkueri `SikepJabatanLapanganUser`, tetapi tidak lagi dirujuk oleh `RoleResolver` maupun `ApproverMappingService` — satu-satunya sisa referensi `SikepJabatanLapanganUser` di `app/` ada di dalam helper ini. Sesuai Out of Scope: disimpan untuk laporan masa depan, lepas dari jalur role.

### UI Vue dan bukti verifikasi

`Admin/Pengguna.vue` (confirmed) memakai komponen Sakai/PrimeVue yang sudah ada (`DataTable`, `Column`, `Tag`, `Select`, `Dialog`, `Button`, `useToast`, `useConfirm`): kolom Peran (`Tag` dari `role`), Status (Aktif/Nonaktif), dropdown ubah role → `admin.pengguna.role`, toggle aktif via konfirmasi → `admin.pengguna.aktif`, dialog tarik + pemilihan role → `admin.pengguna.tarik`, dan teks "Peran ditetapkan manual oleh administrator." Tidak ada sisa prop `is_admin`/`is_katimker`/`is_kabid` atau route `set-admin`. Bukti verifikasi coder (`verification.md`) mencatat `php artisan test` 143 passed (539 assertions), `npm run build` sukses, `vendor/bin/pint` lolos untuk file yang disentuh, dan `route:list` menunjukkan `tarik/role/aktif` ada tanpa `set-admin`; pemetaan test → requirement lengkap, termasuk non-regresi alur tugas NON-MOCK untuk katimker/kabid dan test backfill Opsi A. Tidak ada test runner komponen Vue di proyek, jadi perubahan `.vue` diverifikasi via build + tinjauan manual — dinyatakan eksplisit dan konsisten dengan konteks proyek.

</details>

<details>
<summary>File map</summary>

Semua perubahan ada di commit `29178f7` (base `17afadb`/origin main). Diff penuh: `git show 29178f7`.

- `database/migrations/2026_10_08_100001_add_role_and_aktif_to_users_table.php` — kolom `role`+`aktif`, backfill Opsi A, `down()` drop dua kolom.
- `app/Models/User.php` — fillable/cast `role`+`aktif`, konstanta `ROLE_*`/`ROLES`, `setRole()`; `syncFromSikep` tak diubah.
- `app/Services/Sikep/RoleResolver.php` — resolve dari kolom lokal, fail-closed, lepas dari SIKEP.
- `app/Http/Controllers/Auth/LoginController.php` — gerbang tiga-cabang + pesan Indonesia.
- `app/Http/Controllers/Auth/TwoFactorController.php` — guard fail-closed pending-2FA sebelum `Auth::login()`.
- `app/Http/Middleware/EnsureActiveRole.php` — logout+redirect saat `roles=[]`, pesan tunggal.
- `app/Http/Middleware/EnsureRole.php` — disederhanakan jadi otorisasi per-route.
- `app/Console/Commands/SetAdmin.php` — pakai `setRole()`; `--off`→`role=null`.
- `app/Http/Controllers/Admin/UserController.php` — `tarik`/`ubahRole`/`toggleAktif`, anti-lockout, audit, props baru.
- `app/Services/ApproverMappingService.php` — kandidat dari users lokal, field `kabid_valid`.
- `app/Http/Controllers/Admin/ApproverMappingController.php` — pemetaan field error (Temuan 4).
- `resources/js/Pages/Admin/Pengguna.vue` — UI role/status/tarik Sakai.
- `resources/js/Pages/Admin/ApproverMapping.vue` — badge "bukan Kabid aktif".
- `routes/web.php` — grup `['auth','active-role','two-factor']`, route `tarik/role/aktif`, hapus `set-admin`.
- `bootstrap/app.php` — alias `active-role`.
- `database/factories/UserFactory.php` — state `admin/katimker/kabid/nonaktif`.
- `tests/**` — unit + feature (login gate, EnsureActiveRole, pending-2FA, tarik, anti-lockout, ApproverMapping, backfill, non-regresi NON-MOCK).

</details>
