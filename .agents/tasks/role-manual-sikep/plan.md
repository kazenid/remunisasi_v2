# Implementation Plan — Role Manual (Lepas Role dari SIKEP)

Rencana ini mengikuti `design.md` (final, 0 HIGH) + `requirements.md`, dengan
koreksi keputusan pemilik yang mengunci temuan review terakhir. Setiap langkah
menautkan ke file konkret dan punya verifikasi dengan command nyata proyek.

## Konteks build & test (ditemukan saat eksplorasi)

- Stack: Laravel 13 (`^13.8`), PHP `^8.3`, Inertia 3, Vue 3 + PrimeVue 4 (Sakai/Aura) + Tailwind 4, Vite 8.
- Test runner: PHPUnit 12 via `php artisan test` (lihat `composer.json` script `test`; `phpunit.xml` pakai SQLite `:memory:`, `RefreshDatabase`).
- Build front-end: `npm run build` (vite). Dev: `npm run dev`.
- Lint PHP: `vendor/bin/pint` (laravel/pint ter-install).
- DB app: koneksi default (MySQL/MariaDB dari `.env`); SIKEP koneksi read-only terpisah. Migrasi terakhir yang ada: `2026_10_07_100002_add_two_factor_columns_to_users_table.php` → migrasi baru `2026_10_08_100001_*` sudah berada sesudahnya (dikonfirmasi).
- Tidak ada test runner komponen Vue di proyek → perubahan `Pengguna.vue`/`ApproverMapping.vue` diverifikasi dengan `npm run build` (kompilasi sukses) + verifikasi manual render; bukan unit test JS.

## KOREKSI KEPUTUSAN PEMILIK (OPSI A — mengikat, menang atas teks design.md yang berbeda)

- **Backfill (TEMUAN 1, MEDIUM → Opsi A).** `design.md` §1 menuliskan backfill non-admin
  menjadi `role = null` **dan** `aktif = false`. Keputusan pemilik: **OPSI A**. Backfill
  non-admin lama **HANYA** menyetel `role = null`; `aktif` **DIBIARKAN** pada default `true`
  (JANGAN set `aktif = false`). Admin lama tetap di-backfill `role = 'admin', aktif = true`.
  Login non-admin lama tetap tertutup karena `role = null` (gerbang login 2.4 + RoleResolver
  fail-closed pada `role = null`). Di mana pun `design.md` menulis `aktif = false` pada
  backfill (blok `up()`, "Keputusan backfill", "Prosedur deploy tunggal"), perlakukan
  **Opsi A sebagai yang benar** dan implementasikan sesuai Opsi A.
- **Remediasi user backfill (TEMUAN 2, MEDIUM → otomatis hilang karena Opsi A).** Karena
  user backfill tetap `aktif = true`, Admin cukup memberi role via `ubahRole()` di menu
  Kepegawaian dan user langsung bisa login. `ubahRole()` **TIDAK** menyentuh `aktif`. User
  backfill **tidak** melalui `tarik()` (yang menolak record yang sudah ada) melainkan
  `ubahRole()`. **Tidak ada alur dua-langkah.**
- **Memoisasi `roles()` (TEMUAN 3, NIT, opsional).** Implementasikan memoisasi sederhana
  per-instance `User` BILA tidak menambah kompleksitas berarti; harus invalidasi saat
  `role`/`aktif` berubah dalam request yang sama. Jika ragu, lewati — tidak memblokir.
- **Field pesan error ApproverMapping (TEMUAN 4, NIT → lakukan).** Di
  `ApproverMappingController::store()`, map pesan "bukan Katimker aktif" ke field
  `katimker_sikep_user_id` dan "bukan Kabid aktif" ke field `kabid_sikep_user_id`.

---

# Langkah Implementasi

- [ ] 1. Buat migrasi `add_role_and_aktif_to_users_table` yang menambah kolom `role` (string(20) nullable, after `is_admin`) dan `aktif` (boolean default true, after `role`), dengan backfill **Opsi A** dan `down()` yang hanya menghapus kedua kolom baru.
      Backfill: admin lama (`is_admin = true`) → `role = 'admin', aktif = true`; non-admin lama (`is_admin = false`) → **hanya** `role = null` (JANGAN sentuh `aktif`). `down()` drop `['role','aktif']` saja (jangan sentuh `is_admin`).
      Files: `database/migrations/2026_10_08_100001_add_role_and_aktif_to_users_table.php`
      Verify: `php artisan migrate` lalu `php artisan migrate:rollback` pada DB lokal berhasil tanpa error (atau cukup `php artisan test` yang me-run migrasi via `RefreshDatabase`); kolom `role`/`aktif` ada setelah up, hilang setelah down.

- [ ] 2. Perbarui model `User`: tambah `role`,`aktif` ke `#[Fillable]`; cast `'aktif' => 'boolean'`; konstanta `ROLE_ADMIN/ROLE_KATIMKER/ROLE_KABID` + `ROLES`; method `setRole(?string $role)` (set `role` + selaraskan `is_admin = ($role === ROLE_ADMIN)`, TANPA `save()`). Opsional: memoisasi `roles()` per-instance (TEMUAN 3) hanya jika sepele dan invalidasi saat `role`/`aktif` berubah; jika ragu lewati. `syncFromSikep()` TIDAK diubah; `requiresTwoFactor()` tetap `roles()['is_admin'] === true`.
      Files: `app/Models/User.php`
      Verify: `php artisan test` hijau untuk suite yang menyentuh User (langkah ini belum punya test khusus; verifikasi penuh di langkah 11-14). Minimal: `vendor/bin/pint app/Models/User.php` tanpa error sintaks.

- [ ] 3. Tulis ulang `RoleResolver::resolve()` agar membaca `role`+`aktif` dari tabel `users` lokal (fail-closed: user tak ada / `aktif = false` / `role = null` → `{roles:[], is_*:false}`), bungkus query dengan `try/catch QueryException` + `report($e)` → return kosong. Hapus dependensi `SikepJabatanLapanganUser`. Kontrak keluaran `{roles,is_katimker,is_kabid,is_admin}` tetap. Pakai `User::ROLE_*`.
      Files: `app/Services/Sikep/RoleResolver.php`
      Verify: diuji di langkah 11 (`RoleResolverTest`). Sementara: `vendor/bin/pint app/Services/Sikep/RoleResolver.php`.

- [ ] 4. Ubah `LoginController::store()` menjadi gerbang 3 cabang. Setelah `attempt()` (QueryException → "Integrasi SIKEP tidak tersedia saat ini. Coba lagi nanti."; `null` → "Kredensial yang diberikan tidak cocok."), ambil `$local = User::where('sikep_user_id', $sikepUser->id)->first(['id','sikep_user_id','role','aktif'])` lalu cek berurutan: `null` → pesan 2.2 (JANGAN `syncFromSikep`); `aktif === false` → pesan 2.3; `role === null` → pesan 2.4. Baru resolve role HANYA untuk cabang 2FA (`$roles['is_admin']`), dengan jaring pengaman `roles===[]` → pesan 2.4. Lalu `syncFromSikep` (refresh identitas) + cabang 2FA seperti sekarang. Pesan persis sesuai tabel §4 design.
      Files: `app/Http/Controllers/Auth/LoginController.php`
      Verify: diuji di langkah 12 (`LoginTest`). Sementara: `vendor/bin/pint` pada file.

- [ ] 5. Tambahkan guard fail-closed di `TwoFactorController::verifyChallenge()`: inject `RoleResolver` lewat konstruktor (pola `LoginController`). SETELAH kode terverifikasi (`$ok === true`) dan SEBELUM `Auth::login()`, panggil `resolve($user->sikep_user_id)`; bila `roles === []` → `session()->forget(['2fa.pending_user_id','2fa.remember'])` lalu `redirect()->route('login')->withErrors(['login' => EnsureActiveRole::MESSAGE])`. Butuh langkah 7 (konstanta `MESSAGE`) sudah ada — kerjakan langkah 7 lebih dulu bila mengacu konstanta.
      Files: `app/Http/Controllers/Auth/TwoFactorController.php`
      Verify: diuji di langkah 13 (pending-2FA dinonaktifkan di tengah). Sementara: `vendor/bin/pint` pada file.

- [ ] 6. Ubah `SetAdmin` command memakai `setRole()`: tanpa `--off` → `setRole(ROLE_ADMIN)` + `aktif = true`; dengan `--off` → `setRole(null)` (role=null, is_admin=false), `aktif` TIDAK diubah. `save()` setelah `syncFromSikep`. Pesan hasil tetap Bahasa Indonesia (sesuaikan status "DIJADIKAN/DICABUT admin" dari `role`/`is_admin`, bukan hanya `is_admin` lama).
      Files: `app/Console/Commands/SetAdmin.php`
      Verify: diuji di langkah 14 (backfill + bootstrap admin). Sementara: `vendor/bin/pint` pada file.

- [ ] 7. Buat middleware baru `EnsureActiveRole` dengan konstanta `MESSAGE` tunggal ("Akun Anda dinonaktifkan atau tidak memiliki peran. Hubungi administrator."), daftar `EXCEPT` (`logout`, `two-factor.challenge`, `two-factor.setup`, `two-factor.setup.confirm`, `two-factor.recovery-codes.regenerate`), dan `handle()` yang: lewati bila user null atau route di EXCEPT; bila `$user->roles()['roles'] === []` → `Auth::logout()` + `invalidate()` + `regenerateToken()` + `redirect()->route('login')->withErrors(['login' => self::MESSAGE])`. (Implementasi persis blok §7 design.)
      Files: `app/Http/Middleware/EnsureActiveRole.php`
      Verify: diuji di langkah 13. Sementara: `vendor/bin/pint` pada file.

- [ ] 8. Sederhanakan `EnsureRole::handle()` menjadi otorisasi per-route saja: user null → `abort(403)`; `roles=[]` atau peran tak termasuk yang diizinkan → `abort(403)` (logout `roles=[]` kini ditangani `EnsureActiveRole`). Daftarkan alias `active-role => EnsureActiveRole::class` di `bootstrap/app.php` (pola alias `role`/`two-factor`). Di `routes/web.php`, ubah grup auth menjadi `['auth','active-role','two-factor']` (active-role DI DEPAN two-factor).
      Files: `app/Http/Middleware/EnsureRole.php`, `bootstrap/app.php`, `routes/web.php`
      Verify: `php artisan route:list` tanpa error (alias ter-resolve); diuji penuh di langkah 13.

- [ ] 9. Ubah routes grup `admin` di `routes/web.php`: HAPUS `Route::post('/pengguna/admin', ...)->name('pengguna.set-admin')`; TAMBAH `pengguna.tarik` (POST `/pengguna/tarik`), `pengguna.role` (POST `/pengguna/{user}/role`), `pengguna.aktif` (POST `/pengguna/{user}/aktif`) → `{user}` binding `App\Models\User` (PK lokal `id`). (Langkah 8 sudah mengubah middleware grup auth di file yang sama; koordinasikan satu kali edit.)
      Files: `routes/web.php`
      Verify: `php artisan route:list --name=admin.pengguna` menampilkan `tarik/role/aktif` dan TIDAK ada `set-admin`.

- [ ] 10. Rombak `Admin/UserController`: HAPUS method `setAdmin()` dan dependensi `RoleResolver` dari konstruktor (pertahankan `AuditLogger`). `index()` + `daftarUserLokal()` menampilkan kolom `role`+`aktif` apa adanya (bukan hasil resolve), tambah prop `roleOptions`. `cariPegawai()` set `sudah_ditarik` (ada record `users`) + `role` lokal bila ada, tetap min. 3 karakter. Tambah `tarik()` (guard "sudah ditarik" → `ValidationException` field `sikep_user_id`; cari `SikepUser` aktif; `syncFromSikep` + `setRole($role)` + `aktif=true` + `save`; validasi `role` ∈ `User::ROLES`; audit `pengguna.tarik`), `ubahRole()` (validasi `role` required ∈ `ROLES`; anti-lockout: tak boleh turunkan admin diri sendiri, tak boleh sisakan nol admin aktif; `setRole` + `save`; audit `pengguna.role`; TIDAK menyentuh `aktif`), `toggleAktif()` (validasi `aktif` boolean; anti-lockout: tak boleh nonaktifkan diri sendiri / sisakan nol admin aktif; set `aktif` + `save` tanpa hapus record; audit `pengguna.aktif`). Pesan validasi Bahasa Indonesia persis §6 design. Sebelum mulai, jalankan `grep 'role.update'` di `app/`,`resources/js/`,`tests/` dan ganti pemakai yang hidup ke nama aksi baru.
      Files: `app/Http/Controllers/Admin/UserController.php`
      Verify: diuji di langkah 12 (props `index()`) & langkah 13/`AdminManagementTest` (anti-lockout, audit). Sementara: `vendor/bin/pint` pada file.

- [ ] 11. Perbarui UI `Admin/Pengguna.vue` (gaya Sakai, komponen PrimeVue yang sudah ada — JANGAN buat komponen baru): kolom "Peran" satu `Tag` dari `data.role` (admin=danger, katimker=info, kabid=warn, null="—"); kolom "Status" `Tag` Aktif/Nonaktif (success/secondary); aksi daftar lokal = `Select`/`Dropdown` role + tombol "Simpan" POST `admin.pengguna.role`, dan toggle aktif POST `admin.pengguna.aktif` via `useConfirm`. Kartu cari: tombol "Tarik" buka `Dialog` pemilihan role lalu POST `admin.pengguna.tarik`; untuk `sudah_ditarik === true` tombol di-`disabled` + `v-tooltip` "Sudah ditarik — kelola di daftar user lokal" + `Tag` role lokal bila ada. Ganti teks "Peran Katimker/Kabid berasal dari SIKEP" → "Peran ditetapkan manual oleh administrator." Hapus pemakaian prop lama `is_admin/is_katimker/is_kabid`/fungsi `setAdmin`/route `set-admin`. Pakai `useToast` untuk flash. Aktifkan skill `sakai-ui-ux` saat mengerjakan langkah ini.
      Files: `resources/js/Pages/Admin/Pengguna.vue`
      Verify: `npm run build` sukses tanpa error kompilasi; verifikasi manual render props baru + aksi tarik/ubah/toggle.

- [ ] 12. Ubah `ApproverMappingService`: `listKatimker()`/`listKabid()`/`isKatimker()`/`isKabid()` membaca user lokal (`role`+`aktif`) alih-alih `SikepJabatanLapanganUser`; bentuk keluaran tetap + tambah field aditif `kabid_valid` di `listKatimker()` (nama kabid historis tetap tampil tanpa filter saat ambil nama, `kabid_valid` menandai masih `role='kabid'` & `aktif=true`); pesan `assign()` jadi "Pengguna bukan Katimker aktif." / "Target penilai bukan Kabid aktif." Hapus `use` yang tak terpakai (`SikepJabatanLapanganUser`). Di `ApproverMappingController::store()` (TEMUAN 4): map `InvalidArgumentException` "bukan Katimker aktif" → field `katimker_sikep_user_id`, "bukan Kabid aktif" → field `kabid_sikep_user_id`. `Admin/ApproverMapping.vue`: render badge/`Tag` "bukan Kabid aktif" (severity warn) saat `kabid_valid === false`.
      Files: `app/Services/ApproverMappingService.php`, `app/Http/Controllers/Admin/ApproverMappingController.php`, `resources/js/Pages/Admin/ApproverMapping.vue`
      Verify: diuji di langkah 12-test (`ApproverMappingTest`, langkah 13). `npm run build` sukses untuk perubahan Vue.

- [ ] 13. Perbarui `UserFactory`: tambah `role => null`, `aktif => true` di `definition()`; state `admin()` (role=admin,is_admin=true,aktif=true), `katimker()`, `kabid()` (is_admin=false, aktif=true), `nonaktif()` (aktif=false). Tulis kedua kolom `role`+`is_admin` berpasangan agar invarian terjaga.
      Files: `database/factories/UserFactory.php`
      Verify: `php artisan test` tidak error konstruksi factory (dipakai luas di langkah test berikut).

- [ ] 14. Perbarui unit test resolusi role & tambah kasus baru: `RoleResolverTest` (user `role=admin,aktif=true` → is_admin; `aktif=false` → kosong; `role=null` → kosong; `role=katimker` → is_katimker) dan test invarian `User::setRole()`.
      Files: `tests/Unit/RoleResolverTest.php` (+ opsional `tests/Unit/UserTest.php` untuk `setRole`)
      Verify: `php artisan test --filter RoleResolverTest` hijau.

- [ ] 15. Perbarui + tambah feature test gerbang login: sesuaikan `LoginTest` ke alur baru (record lokal wajib ada sebelum login; perbarui string pesan lama "memiliki peran (Katimker..." → pesan baru; cari dulu via grep di `tests/`); tambah kasus 2.2 (tanpa record → `assertDatabaseCount('users',0)` + pesan 2.2), 2.3 (`aktif=false` → pesan 2.3), 2.4 (`role=null` → pesan 2.4), 2.5 (record valid → sukses + role dari kolom lokal).
      Files: `tests/Feature/LoginTest.php`
      Verify: `php artisan test --filter LoginTest` hijau.

- [ ] 16. Perbarui `AdminManagementTest` (WAJIB — route `set-admin` dihapus): `actingAsAdmin()` pakai `User::factory()->admin()->create([...])`; `test_admin_menjadikan_pengguna_lain_admin` → POST `admin.pengguna.role` dengan `{role:'admin'}` pada target `User::factory()->katimker()->create(['sikep_user_id'=>222])`, assert `is_admin===true` & `role==='admin'`, audit `pengguna.role`; `test_admin_tidak_bisa_mencabut_admin_diri_sendiri` → POST `admin.pengguna.role` self `{role:'katimker'}` → `assertSessionHasErrors('role')`, admin tetap `role==='admin'` (opsional tambah kasus toggle aktif self). Tambah/selaraskan feature test props `UserController::index()` (`users.0.role`, `users.0.aktif`, `roleOptions`, `hasilCari.*.sudah_ditarik`) via `AssertableInertia`.
      Files: `tests/Feature/AdminManagementTest.php`
      Verify: `php artisan test --filter AdminManagementTest` hijau.

- [ ] 17. Tambah feature test penegakan sesi (`EnsureActiveRole`) & pending-2FA: user aktif ber-role akses route ber-peran (sukses) → `update(['aktif'=>false])` → request berikut redirect `login` + `assertGuest()` + `assertSessionHasErrors('login')`; kasus `role=null` di tengah; kasus dashboard `/` (tanpa `role:`); kasus request Inertia GET (header `X-Inertia`) assert pesan via `errors.login`. Pending-2FA (di `TwoFactorAuthTest` atau test baru): admin 2FA terkonfirmasi + sesi `2fa.pending_user_id`, lalu dinonaktifkan, POST `two-factor.challenge.verify` kode valid → `assertGuest()` + redirect `login`, BUKAN `dashboard` (resolver boleh di-mock `roles=[]`).
      Files: `tests/Feature/EnsureActiveRoleTest.php` (baru), `tests/Feature/TwoFactorAuthTest.php`
      Verify: `php artisan test --filter "EnsureActiveRole|TwoFactor"` hijau.

- [ ] 18. Perbarui `ApproverMappingTest` ke kandidat role lokal + validasi 5.3: setup pakai `User::factory()->katimker()` / `->kabid()` (bukan seed `SikepJabatanLapanganUser`); uji `assign()` menolak target non-Kabid-aktif / non-Katimker-aktif dengan pesan baru; (opsional) uji `kabid_valid=false` saat kabid dinonaktifkan. Audit test lain yang mem-mock `RoleResolver` agar user factory ber-role/aktif konsisten (hindari `EnsureActiveRole` logout tak terduga).
      Files: `tests/Feature/ApproverMappingTest.php` (+ audit factory di test fitur lain yang terdampak)
      Verify: `php artisan test --filter ApproverMappingTest` hijau.

- [ ] 19. Tambah feature test non-regresi NON-MOCK & backfill+bootstrap: Katimker nyata `User::factory()->katimker()->create()` → login → POST `katimker.tugas.store` sukses; Kabid nyata → aksi `kabid.penilaian.approve`/`reject` pada binaannya sukses (tanpa mock resolver, menembus `active-role`+`role:`+resolver baru). Backfill: buat beberapa non-admin tanpa admin, jalankan migrasi backfill + `Artisan::call('app:set-admin', ...)`, assert non-admin → `role=null` **dan `aktif=true` (Opsi A)**, user command → `role=admin,is_admin=true,aktif=true` dan bisa login.
      Files: `tests/Feature/RoleManualNonRegressionTest.php` (baru), `tests/Feature/BackfillBootstrapAdminTest.php` (baru)
      Verify: `php artisan test --filter "NonRegression|Backfill"` hijau.

- [ ] 20. Verifikasi akhir menyeluruh: jalankan seluruh suite, build front-end, dan lint.
      Files: — (tidak ada perubahan file; gerbang kualitas)
      Verify: `php artisan test` seluruhnya hijau; `npm run build` sukses; `vendor/bin/pint --test` tanpa pelanggaran (atau `vendor/bin/pint` lalu konfirmasi tidak ada diff tak terduga). Bila ada test lain yang mem-mock `RoleResolver` masih merah karena factory tanpa `role`/`aktif`, selaraskan ke state ber-role (lihat §9 design) hingga hijau.

## Catatan verifikasi global

- Perintah build/test proyek: `php artisan test` (PHPUnit, SQLite in-memory, RefreshDatabase), `npm run build` (vite), `vendor/bin/pint` (lint PHP).
- Tidak ada test runner komponen Vue → `Pengguna.vue`/`ApproverMapping.vue` diverifikasi via `npm run build` + manual, bukan unit test JS. Nyatakan ini di laporan implementasi.
- Semua pesan/UI berbahasa Indonesia; UI mengikuti Sakai (PrimeVue + Aura + Tailwind + Inertia), memakai komponen yang sudah ada.
- Invarian `role='admin' ⇔ is_admin=true` ditegakkan HANYA lewat `User::setRole()`, kecuali dua pengecualian terdokumentasi: backfill migrasi dan `UserFactory` state (menulis kedua kolom berpasangan).
