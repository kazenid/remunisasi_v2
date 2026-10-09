# Design Review — Role Manual (Lepas Role dari SIKEP)

Iterasi review: 4 (fresh, tanpa konteks penyusun desain).
Dokumen ditinjau: `.agents/tasks/role-manual-sikep/design.md` terhadap
`.agents/tasks/role-manual-sikep/requirements.md`.
Metode: setiap klaim desain atas kode nyata diverifikasi dengan membaca sumber
di `f:\LARAVEL\remunisasi_v2`.

Verdict: **CHANGES_REQUESTED** (0 HIGH, 2 MEDIUM, 2 NIT).

Desain ini matang dan sudah menutup hampir semua kelas risiko blocking yang
diminta (lockout admin, sesi lama user nonaktif termasuk celah pending-2FA,
sinkron `is_admin`↔`role`, gerbang login & Invarian Urutan, ApproverMapping dari
role lokal, non-regresi alur tugas, pembaruan test/factory). Temuan tersisa
berpusat pada SATU keputusan backfill yang bertentangan dengan requirements dan
konsekuensi alur turunannya.

---

## Temuan

### 1. [MEDIUM] Backfill menyetel `aktif = false` untuk non-admin — bertentangan dengan Requirement 1.4 / A3 / Acceptance #2

**Masalah.** Desain §1 (blok `up()` migrasi dan "Keputusan backfill (eksplisit)")
menyetel non-admin lama ke `role = null` **DAN** `aktif = false`:

```php
DB::table('users')->where('is_admin', false)->update([
    'role' => null,
    'aktif' => false,
]);
```

Requirements hanya memandatkan `role = null`, TIDAK menyetel `aktif`:

- Req 1.4: "...THE SYSTEM SHALL mengisi `role = null` (user tersebut tidak dapat
  login sampai Admin menetapkan role)." — hanya `role`.
- Asumsi A3 (requirements): "...user lama lain yang tidak bisa dipetakan ke role
  baru diberi `role = null`" — hanya `role`, tidak menyebut `aktif`.
- Acceptance #2: "...user lain yang tak terpetakan memiliki `role = null`." —
  hanya `role`.

Default `aktif` menurut Req 1.1/A3 adalah `true`. Jadi desain menambahkan efek
(`aktif = false`) yang tidak ada di requirements dan secara spesifik bertentangan
dengan A3. Karena gerbang login (2.4) dan `RoleResolver` sama-sama sudah
fail-closed pada `role = null`, menyetel `aktif = false` TIDAK diperlukan untuk
memblokir login — ia hanya mengubah semantik status akun dan alur remediasi
Admin (lihat Temuan 2).

**Lokasi.** design.md §1 (`up()` migrasi, "Keputusan backfill (eksplisit)",
"Prosedur deploy tunggal") vs requirements.md Req 1.4, Asumsi A3, Acceptance #2.

**Perbaikan konkret (pilih satu, lalu buat konsisten di seluruh §1):**

- Opsi A (selaras requirements — disarankan): backfill non-admin HANYA menyetel
  `role = null` dan membiarkan `aktif` pada default `true`:
  ```php
  DB::table('users')->where('is_admin', false)->update(['role' => null]);
  ```
  Login tetap tertutup oleh gerbang 2.4 (`role = null`). `aktif` biarkan `true`.
  Konsekuensi: prosedur deploy §1 tetap aman karena yang memblokir adalah
  `role = null`, bukan `aktif`.
- Opsi B (pertahankan `aktif = false`): perbarui requirements (Req 1.4, A3,
  Acceptance #2) agar eksplisit menyatakan non-admin backfill menjadi
  `role = null, aktif = false`, dan sinkronkan Temuan 2 (alur Admin wajib
  mengaktifkan ulang + menetapkan role). Keputusan ini harus dinyatakan sebagai
  perubahan requirements yang disetujui pemilik, bukan keputusan sepihak desain.

Apa pun pilihannya, hilangkan kontradiksi antara teks desain dan A3/Acceptance #2.

---

### 2. [MEDIUM] Alur remediasi Admin untuk user hasil backfill tidak lengkap (akibat `aktif = false` + `tarik()` menolak record lama)

**Masalah.** Dengan backfill §1 (Temuan 1), setiap non-admin lama menjadi
`role = null, aktif = false`. Satu-satunya cara Admin mengembalikan akses user
ini adalah lewat menu Kepegawaian, tetapi:

- `tarik()` (§6) menolak record yang sudah ada:
  `User::where('sikep_user_id', ...)->exists()` → `ValidationException`
  "Pegawai sudah ditarik. Kelola peran/status di daftar user lokal." Jadi jalur
  "tarik + tetapkan role + aktif=true" TIDAK berlaku untuk user backfill.
- `ubahRole()` (§6) HANYA memanggil `setRole()` + `save()` + audit. Ia TIDAK
  menyentuh `aktif`. Jadi setelah `ubahRole`, user tetap `aktif = false` dan
  TETAP ditolak login (gerbang 2.3 "Akun Anda dinonaktifkan").
- Akibatnya Admin WAJIB melakukan DUA aksi terpisah (`ubahRole` lalu
  `toggleAktif(true)`) untuk setiap user backfill. Alur dua-langkah ini tidak
  dinyatakan di desain, dan tidak ada petunjuk UI yang memandunya. Risiko nyata:
  Admin menetapkan role, mengira user bisa login, padahal masih `aktif = false`.

**Lokasi.** design.md §6 (`tarik()` guard "sudah ditarik", `ubahRole()` yang tak
menyentuh `aktif`) berinteraksi dengan §1 backfill `aktif = false`.

**Perbaikan konkret (tergantung resolusi Temuan 1):**

- Bila Temuan 1 memilih **Opsi A** (backfill `aktif = true`): temuan ini otomatis
  hilang — user backfill cukup diberi role via `ubahRole` dan langsung bisa login.
  Nyatakan ini di §6.
- Bila Temuan 1 memilih **Opsi B** (backfill `aktif = false`): tetapkan alur
  remediasi eksplisit di §6, mis. SALAH SATU:
  (i) `ubahRole()` ikut menyetel `aktif = true` saat menetapkan role non-null
  (dengan tetap menjaga guard anti-lockout), ATAU
  (ii) UI Kepegawaian menampilkan hint/urutan wajib "aktifkan + tetapkan role"
  untuk user `aktif = false, role = null`, dan dokumentasikan bahwa dua aksi
  diperlukan. Pilih satu dan buat deterministik.

---

### 3. [NIT] `roles()` di-resolve berulang per request (query lokal redundan)

**Masalah.** Setelah penambahan `EnsureActiveRole`, pada route ber-`role:` sebuah
request memanggil `$user->roles()` di tiga tempat: `EnsureActiveRole`
(cek `roles === []`), `EnsureTwoFactor` (`requiresTwoFactor()`), dan `EnsureRole`
(cek interseksi). Masing-masing memicu `RoleResolver::resolve()` → satu query
`users` lokal (3 query identik per request). NFR5 (tak ada query SIKEP di jalur
panas) TETAP terpenuhi karena kini query lokal, jadi ini bukan pelanggaran — hanya
redundansi. Desain tidak menyebut memoisasi.

**Lokasi.** design.md §3/§7 (`roles()` dipanggil di beberapa middleware).

**Perbaikan konkret (opsional).** Tambahkan satu kalimat di §3: pertimbangkan
memoisasi hasil `roles()` per instance `User` (mis. cache properti di model) agar
beberapa middleware pada satu request berbagi satu resolusi. Tidak memengaruhi
verdict.

---

### 4. [NIT] Pesan validasi `assign()` untuk katimker tak valid menempel di field `kabid_sikep_user_id`

**Masalah.** `ApproverMappingController::store()` (diverifikasi) menangkap
`InvalidArgumentException` dari `assign()` dan selalu melampirkannya ke field
`kabid_sikep_user_id`. Dengan pesan baru §8 "Pengguna bukan Katimker aktif."
(kesalahan pada sisi KATIMKER), error muncul di field KABID — mismatch kecil UX.
Ini perilaku pre-existing (bukan diperkenalkan desain), tetapi karena §8 mengubah
teks pesan, layak dicatat agar implementer mempertimbangkan memetakan error
katimker ke field `katimker_sikep_user_id`.

**Lokasi.** design.md §8 (pesan `assign()`) vs `ApproverMappingController::store()`.

**Perbaikan konkret (opsional).** Di §8, catat bahwa controller sebaiknya
memetakan pesan "bukan Katimker aktif" ke field `katimker_sikep_user_id` dan
"bukan Kabid aktif" ke `kabid_sikep_user_id`. Tidak memengaruhi verdict.

---

## Verified Assumptions (klaim desain yang DICEK ke kode nyata dan BENAR)

1. **Route `two-factor.challenge` & `two-factor.challenge.verify` berada DI LUAR
   grup `auth`.** Benar — `routes/web.php` mendefinisikannya sebelum
   `Route::middleware(['auth','two-factor'])->group(...)`. Celah pending-2FA nyata.
2. **`TwoFactorController::verifyChallenge()` tidak mengecek `aktif`/`role`
   sebelum `Auth::login()`.** Benar — hanya cek `two_factor_secret`/
   `hasConfirmedTwoFactor()` lalu verifikasi kode, lalu `Auth::login()`. Guard
   fail-closed yang diusulkan valid dan tepat posisinya.
3. **`TwoFactorController` konstruktor hanya inject `TwoFactorService`.** Benar —
   menambah `RoleResolver` adalah perubahan nyata, mengikuti pola `LoginController`.
4. **`LoginController::store()` saat ini memakai `$roles['roles'] === []` sebagai
   satu pesan generik "Akun Anda belum memiliki peran (Katimker/Kabid/Admin)...".**
   Benar — teks persis cocok; penyederhanaan ke tiga pesan 2.2/2.3/2.4 adalah
   perubahan nyata.
5. **`User::syncFromSikep()` hanya menulis identitas (username, nama, no_absen,
   token_pegawai, last_sync_at), tidak menyentuh role/aktif/is_admin.** Benar.
6. **`User::roles()` mendelegasikan ke `RoleResolver::resolve()`;
   `requiresTwoFactor()` = `roles()['is_admin'] === true`.** Benar.
7. **Fillable/casts `User` belum memuat `role`/`aktif`.** Benar.
8. **`RoleResolver::resolve()` saat ini menanyakan `SikepJabatanLapanganUser` +
   `users.is_admin`.** Benar — perubahan ke kolom lokal valid.
9. **`EnsureRole` saat ini menangani `roles=[]` via `abort(403)`.** Benar —
   penyederhanaan yang diusulkan konsisten dengan pemindahan logout ke
   `EnsureActiveRole`.
10. **`SetAdmin` command hanya menulis `is_admin`.** Benar — migrasi ke `setRole()`
    adalah perubahan nyata.
11. **`UserController::setAdmin()` ada, menulis `is_admin` langsung, memakai audit
    `'role.update'`, inject `RoleResolver`.** Benar — penghapusan method + route
    `pengguna.set-admin` konsisten.
12. **Grep `'role.update'` hanya cocok di `UserController::setAdmin()` dan
    `tests/Feature/AdminManagementTest.php`** (selain dokumen task). Benar — tidak
    ada `AuditLogController`/filter UI yang meng-hardcode string tersebut.
13. **`AdminManagementTest.php` memanggil `route('admin.pengguna.set-admin')` di
    dua test, meng-assert audit `'role.update'`, `assertSessionHasErrors('is_admin')`,
    dan `actingAsAdmin()` memakai `is_admin => true` tanpa `role`.** Benar — kedua
    test AKAN error `RouteNotFoundException` bila route dihapus; §9 menanganinya.
14. **`bootstrap/app.php` punya alias `role` & `two-factor`.** Benar —
    penambahan alias `active-role` mengikuti pola yang sama.
15. **`Login.vue` hanya merender `form.errors.login` (Message + small), tidak
    membaca prop `status`/`flash`.** Benar — keputusan `redirect()->withErrors()`
    adalah jalur yang pasti tampil.
16. **`UserFactory` saat ini hanya punya state `admin()` (menulis `is_admin`),
    definition tanpa `role`/`aktif`.** Benar.
17. **Migrasi existing terakhir `2026_10_07_100002`; migrasi baru
    `2026_10_08_100001` berada sesudahnya.** Benar.
18. **Stack `laravel/framework: ^13.8` (Laravel 13).** Benar — sesuai `composer.json`.
19. **`ApproverMapping` dipakai lintas kode via kunci `sikep_user_id`
    (`kabidFor`, `TaskService`, `AssignmentService`, `ReviewController`,
    `RecapController`, `IndividualTargetController`).** Benar — mempertahankan
    kunci `sikep_user_id` menjaga non-regresi.
20. **`ApproverMappingController::store()` sudah menangkap `InvalidArgumentException`
    dari `assign()` dan mengubahnya menjadi `ValidationException` (Req 5.3 jalur
    pesan sudah ada).** Benar.
21. **`DashboardController` me-resolve `roles` untuk memilih blok data; `/` berada
    di grup `['auth','two-factor']` TANPA `role:`.** Benar — maka `EnsureActiveRole`
    pada seluruh grup auth memang diperlukan agar dashboard tercakup.
22. **`EnsureTwoFactor` memanggil `requiresTwoFactor()` (→ `roles()`), dan
    mengecualikan `two-factor.setup/confirm/logout`.** Benar — urutan
    `active-role` sebelum `two-factor` membuat admin nonaktif dilogout sebelum
    evaluasi 2FA.

## Unverified / Wrong Assumptions

- **WRONG — Backfill `aktif`.** Desain mengklaim menyetel non-admin ke
  `aktif = false` "sesuai keputusan pemilik dan Requirement 1.4 / A3". Faktanya
  Req 1.4, A3, dan Acceptance #2 HANYA memandatkan `role = null` dan A3 justru
  menyiratkan `aktif` tetap default `true`. Lihat Temuan 1 (MEDIUM).
- **UNVERIFIED — Perilaku Inertia GET redirect + `withErrors` dari middleware
  pada halaman login.** Desain menyatakan jalur `Auth::logout()` →
  `invalidate()` → `regenerateToken()` → `redirect()->withErrors()` pasti
  menampilkan pesan karena `Login.vue` membaca `form.errors.login`. Pembacaan
  komponen `Login.vue` sudah dikonfirmasi benar; namun perilaku runtime end-to-end
  pada request `X-Inertia` (status 409 vs 302 dan propagasi `errors` bag) belum
  dieksekusi di lingkungan ini — desain sendiri menugaskan feature test khusus
  (§9) untuk membuktikannya. Dicatat sebagai hal yang HARUS dibuktikan test, bukan
  temuan blocking: logikanya sudah benar secara desain.
- **UNVERIFIED — Tidak ada test runner komponen Vue di proyek.** Desain mengklaim
  tidak ada unit test komponen Vue sehingga `Pengguna.vue`/`ApproverMapping.vue`
  diverifikasi manual. Tidak diverifikasi ulang di review ini (tidak memengaruhi
  verdict); konsisten dengan ketiadaan konfigurasi test JS yang terlihat.

---

## Ringkasan Keputusan Wajib (7) — status eksplisit

1. **Skema & backfill** — ditetapkan, TAPI bagian `aktif = false` backfill
   bertentangan dengan requirements (Temuan 1, MEDIUM).
2. **Gerbang login & Invarian Urutan** — eksplisit, tiga pesan ditentukan oleh
   cek langkah 3; resolver hanya untuk cabang 2FA. Jelas, tanpa ambiguitas.
3. **Menu Kepegawaian (tarik/ubah role/toggle aktif + anti-lockout + audit)** —
   eksplisit; namun alur remediasi user backfill tak lengkap (Temuan 2, MEDIUM).
4. **ApproverMapping dari role lokal** — eksplisit, kunci `sikep_user_id`
   dipertahankan, `kabid_valid` aditif. Jelas.
5. **`app:set-admin`** — eksplisit (`setRole()`, `--off` → `role=null`, `aktif`
   tak diubah). Jelas.
6. **Penegakan sesi berjalan (`EnsureActiveRole`) + celah pending-2FA** —
   eksplisit, tiga fase (pra-login/pending-2FA/pasca-login) tertutup. Jelas.
7. **Role resolution lepas dari SIKEP + test/factory** — eksplisit, fail-closed,
   daftar konsumen lengkap, rencana test menyeluruh (termasuk AdminManagementTest
   yang terbukti pecah). Jelas.

Blocking criteria dari instruksi: (a) lockout admin — ditangani; (b) sesi lama
user nonaktif — ditangani (termasuk pending-2FA); (c) test/factory pecah —
ditangani; (d) sinkron `is_admin`↔`role` — ditangani; (e) backfill existing —
**ditetapkan tetapi bertentangan dengan requirements (Temuan 1)**; (f) gerbang
login/Invarian Urutan — ditangani; (g) ApproverMapping role lokal — ditangani;
(h) regresi alur tugas — ditangani (test non-mock).
