# Requirements — Role Manual (Lepas Role dari SIKEP)

## Summary

Saat ini role Katimker/Kabid diturunkan otomatis dari jabatan lapangan SIKEP
(`ms_jabatan_lapangan_user`) lewat `RoleResolver`, dengan konstanta
`SikepUser::JABATAN_KEPALA_TIM = 3` dan `JABATAN_KABID = [10,11,12]`. Ternyata
jabatan "kepala tim" (id 3) di SIKEP adalah ketua **tim kerja unit**, bukan
Katimker dalam hierarki remunisasi yang berada di bawah Kabid. Jadi sumber role
dari SIKEP salah untuk konteks aplikasi ini.

Keputusan pemilik (final): SIKEP tetap menjadi (a) direktori data kepegawaian
read-only (`ms_user`) dan (b) sumber autentikasi (verifikasi password), tetapi
**tidak lagi** menentukan role. Role ditentukan manual oleh Admin dan disimpan
di DB remunisasi, satu user satu role (`admin | katimker | kabid`), dengan
status `aktif` on/off. Admin menarik (import) pegawai dari SIKEP lewat menu
Kepegawaian dan menetapkan role saat menarik. Hanya user yang sudah ditarik,
`aktif = true`, dan punya role yang boleh memakai aplikasi.

Perubahan ini menyentuh: migrasi skema `users`, gerbang login
(`LoginController`, `RoleResolver`), menu Kepegawaian (`UserController` +
halaman `Admin/Pengguna`), pemetaan approver (`ApproverMappingService` +
`ApproverMappingController`), command `app:set-admin`, penegakan sesi berjalan
(`EnsureRole` / middleware auth), serta factory/test yang masih mengandalkan
role dari SIKEP.

### Asumsi (ditandai eksplisit untuk ditinjau pada fase desain)

- A1. `is_admin` dipertahankan demi kompatibilitas; `role` menjadi sumber
  kebenaran dan keduanya diselaraskan (`role = 'admin'` ⇔ `is_admin = true`).
- A2. Nilai `role` disimpan sebagai string nullable dengan domain tertutup
  `{admin, katimker, kabid}`; `null` berarti belum punya role (tidak boleh
  login).
- A3. Default `aktif` untuk record yang ditarik Admin adalah `true`. Pada
  backfill, user yang sudah ada `is_admin = true` dipetakan ke
  `role = 'admin', aktif = true`; user lama lain yang tidak bisa dipetakan ke
  role baru diberi `role = null` (sehingga tidak bisa login sampai Admin
  menetapkan role) — ini sesuai keputusan "SIKEP tidak lagi menentukan role".
- A4. "Menarik pegawai" memakai mekanisme `User::syncFromSikep()` yang sudah ada;
  menu Kepegawaian memperluas `UserController` yang sudah ada (bukan controller
  baru).
- A5. `ApproverMapping` tetap memakai `sikep_user_id` sebagai kunci; tabel/model
  tidak dirombak. Yang berubah hanya aturan validasi sumber kandidat.
- A6. Penegakan `aktif = false` pada sesi berjalan dilakukan di lapisan
  middleware request (bukan hanya saat login), agar user yang dinonaktifkan
  tidak bisa melanjutkan sesi lama.

---

## Functional Requirements

### 1. Skema DB & Backfill

**User Story:** Sebagai pengembang, saya ingin tabel `users` menyimpan `role`
dan `aktif`, agar role tidak lagi bergantung pada SIKEP dan status akun bisa
dikontrol.

- 1.1 THE SYSTEM SHALL menambahkan kolom `role` (string nullable, domain
  `admin|katimker|kabid`) dan `aktif` (boolean, default `true`) pada tabel
  `users` via migrasi.
- 1.2 THE SYSTEM SHALL mempertahankan kolom `is_admin` yang sudah ada.
- 1.3 WHEN migrasi backfill dijalankan, THE SYSTEM SHALL mengisi
  `role = 'admin', aktif = true` untuk setiap user dengan `is_admin = true`.
- 1.4 WHEN migrasi backfill dijalankan untuk user yang `is_admin = false` dan
  tidak dapat dipetakan ke role baru, THE SYSTEM SHALL mengisi `role = null`
  (user tersebut tidak dapat login sampai Admin menetapkan role).
- 1.5 THE SYSTEM SHALL menjaga invarian `role = 'admin'` ⇔ `is_admin = true`:
  setiap perubahan `role` menyelaraskan `is_admin`, dan sebaliknya.
- 1.6 WHERE migrasi perlu di-rollback, THE SYSTEM SHALL menyediakan `down()`
  yang menghapus kolom `role` dan `aktif` tanpa menyentuh `is_admin`.

### 2. Gerbang Login

**User Story:** Sebagai sistem, saya ingin menolak login untuk akun yang belum
ditarik, nonaktif, atau tanpa role, dengan pesan Indonesia yang jelas.

- 2.1 THE SYSTEM SHALL tetap memverifikasi kredensial terhadap SIKEP
  (`SikepAuthService::attempt`) sebagai langkah pertama; kegagalan verifikasi
  menghasilkan pesan generik yang sudah ada ("Kredensial yang diberikan tidak
  cocok.").
- 2.2 WHEN kredensial SIKEP valid DAN belum ada record `users` lokal untuk
  pegawai itu, THE SYSTEM SHALL menolak login dengan pesan: "Akun Anda belum
  terdaftar/diaktifkan di aplikasi. Hubungi administrator."
- 2.3 WHEN record lokal ada tetapi `aktif = false`, THE SYSTEM SHALL menolak
  login dengan pesan yang menjelaskan akun dinonaktifkan (mis. "Akun Anda
  dinonaktifkan. Hubungi administrator.").
- 2.4 WHEN record lokal ada dan `aktif = true` tetapi `role` kosong, THE SYSTEM
  SHALL menolak login dengan pesan yang menjelaskan belum ada role (mis. "Akun
  Anda belum memiliki peran. Hubungi administrator.").
- 2.5 WHEN record lokal `aktif = true` dan memiliki `role` sah, THE SYSTEM SHALL
  menetapkan role dari kolom `role` lokal (BUKAN dari jabatan SIKEP) lalu
  melanjutkan alur login (termasuk cabang 2FA admin yang sudah ada).
- 2.6 THE SYSTEM SHALL menetapkan role dari DB lokal tanpa memanggil jalur
  jabatan SIKEP di `RoleResolver`.
- 2.7 WHEN integrasi SIKEP tidak tersedia saat verifikasi password, THE SYSTEM
  SHALL tetap menolak login dengan pesan "Integrasi SIKEP tidak tersedia saat
  ini. Coba lagi nanti." (perilaku yang sudah ada dipertahankan).
- 2.8 THE SYSTEM SHALL mempertahankan penegakan 2FA untuk role `admin`.

### 3. Menu Kepegawaian — Tarik Pegawai & Tetapkan Role

**User Story:** Sebagai Admin, saya ingin mencari pegawai di SIKEP lalu menarik
mereka ke aplikasi sambil menetapkan role, agar mereka dapat memakai aplikasi.

- 3.1 THE SYSTEM SHALL menyediakan pencarian pegawai SIKEP aktif (minimal 3
  karakter) di menu Kepegawaian (perluasan `UserController`).
- 3.2 WHEN Admin menarik seorang pegawai, THE SYSTEM SHALL membuat/menyinkronkan
  record `users` lokal via `syncFromSikep` dan menetapkan `role` yang dipilih
  (`admin|katimker|kabid`) serta `aktif = true`.
- 3.3 THE SYSTEM SHALL memvalidasi `role` terhadap domain tertutup; nilai di luar
  `{admin, katimker, kabid}` ditolak dengan error validasi.
- 3.4 THE SYSTEM SHALL menolak penarikan pegawai yang tidak ditemukan/aktif di
  SIKEP dengan pesan Indonesia yang jelas.
- 3.5 WHEN `role = 'admin'` ditetapkan, THE SYSTEM SHALL menyelaraskan
  `is_admin = true` (dan sebaliknya untuk role non-admin → `is_admin = false`).

### 4. Menu Kepegawaian — Ubah Role & Toggle Aktif (Audit + Anti-Lockout)

**User Story:** Sebagai Admin, saya ingin mengubah role dan mengaktifkan/
menonaktifkan user, dengan jejak audit dan proteksi agar tidak mengunci diri
sendiri.

- 4.1 THE SYSTEM SHALL mengizinkan Admin mengubah `role` user lokal; perubahan
  menyelaraskan `is_admin` sesuai aturan 1.5.
- 4.2 THE SYSTEM SHALL mengizinkan Admin menyetel `aktif = true|false` pada user
  lokal.
- 4.3 WHEN user dinonaktifkan, THE SYSTEM SHALL mempertahankan record (tidak
  menghapus) agar tetap auditable.
- 4.4 THE SYSTEM SHALL mencatat audit (`AuditLogger`) untuk setiap perubahan
  `role` dan `aktif`, mencakup nilai lama → baru dan `sikep_user_id` target serta
  aktor.
- 4.5 THE SYSTEM SHALL mencegah Admin menonaktifkan dirinya sendiri atau
  menurunkan role dirinya sendiri dari `admin` (anti-lockout), dengan pesan
  validasi Indonesia yang jelas.
- 4.6 WHERE tindakan Admin akan menyisakan nol admin aktif di sistem, THE SYSTEM
  SHALL menolak tindakan tersebut (guard tambahan terhadap lockout global).
- 4.7 THE SYSTEM SHALL menampilkan daftar user lokal beserta `role`, status
  `aktif`, dan `last_sync_at` di halaman Kepegawaian.

### 5. ApproverMapping Berbasis Role Lokal

**User Story:** Sebagai Admin, saya ingin memetakan Katimker → Kabid dari user
lokal yang ber-role sesuai dan aktif, bukan dari jabatan SIKEP.

- 5.1 THE SYSTEM SHALL menyusun daftar kandidat Katimker dari user lokal dengan
  `role = 'katimker'` dan `aktif = true` (bukan dari `ms_jabatan_lapangan_user`).
- 5.2 THE SYSTEM SHALL menyusun daftar kandidat Kabid dari user lokal dengan
  `role = 'kabid'` dan `aktif = true`.
- 5.3 WHEN Admin menetapkan pemetaan, THE SYSTEM SHALL memvalidasi bahwa
  `katimker_sikep_user_id` merujuk user lokal ber-role `katimker` aktif, dan
  `kabid_sikep_user_id` merujuk user lokal ber-role `kabid` aktif; jika tidak,
  tolak dengan pesan Indonesia yang jelas.
- 5.4 THE SYSTEM SHALL tetap memakai `sikep_user_id` sebagai kunci pada tabel/
  model `ApproverMapping` tanpa merombak strukturnya.
- 5.5 THE SYSTEM SHALL tetap mencatat audit pemetaan seperti perilaku saat ini.

### 6. Command Artisan `app:set-admin`

**User Story:** Sebagai operator, saya ingin command bootstrap admin pertama
tetap berfungsi dengan skema role baru.

- 6.1 THE SYSTEM SHALL mempertahankan command `php artisan app:set-admin {login}`
  dan opsi `--off`.
- 6.2 WHEN command dijalankan tanpa `--off`, THE SYSTEM SHALL menyetel
  `role = 'admin'`, `is_admin = true`, dan `aktif = true` pada user target
  (sync dari SIKEP bila belum ada).
- 6.3 WHEN command dijalankan dengan `--off`, THE SYSTEM SHALL mencabut status
  admin; THE SYSTEM SHALL mendefinisikan role resultan secara eksplisit (mis.
  `role = null`, `is_admin = false`) dan menjaga invarian 1.5.
- 6.4 THE SYSTEM SHALL melaporkan hasil (dijadikan/dicabut) dengan pesan
  Indonesia seperti sekarang.

### 7. Penegakan `aktif = false` pada Sesi Berjalan

**User Story:** Sebagai sistem, saya ingin user yang dinonaktifkan tidak bisa
melanjutkan sesi lama.

- 7.1 WHILE sebuah sesi aktif, WHEN `users.aktif = false` untuk user tersebut,
  THE SYSTEM SHALL mengakhiri sesi (logout) dan mengarahkan ke login dengan pesan
  Indonesia yang jelas.
- 7.2 WHEN `users.role` menjadi `null` di tengah sesi, THE SYSTEM SHALL
  memperlakukan user sebagai tanpa akses (ditolak/dilogout) secara konsisten
  dengan gerbang login.
- 7.3 THE SYSTEM SHALL menegakkan 7.1–7.2 di lapisan middleware request untuk
  rute terautentikasi (bukan hanya pada login).

### 8. Role Resolution dari DB Lokal (Lepas dari SIKEP)

**User Story:** Sebagai pengembang, saya ingin semua konsumen role memakai role
lokal, agar perilaku konsisten.

- 8.1 THE SYSTEM SHALL mengubah `RoleResolver` / `User::roles()` agar menurunkan
  `roles`, `is_katimker`, `is_kabid`, `is_admin` dari kolom `role` lokal.
- 8.2 THE SYSTEM SHALL mempertahankan konstanta `SikepUser::JABATAN_KEPALA_TIM`,
  `SikepUser::JABATAN_KABID`, serta helper `SikepUser::isKatimker()` /
  `isKabid()`, TANPA memakainya di jalur penentuan role (disimpan untuk laporan
  masa depan).
- 8.3 THE SYSTEM SHALL memastikan konsumen `roles()` yang sudah ada
  (`HandleInertiaRequests`, `DashboardController`, `EnsureRole`,
  `IndividualTargetController`, policies) tetap bekerja dengan sumber role baru
  tanpa perubahan kontrak nilai (`is_katimker/is_kabid/is_admin` tetap boolean).

### 9. Non-Regresi Alur Tugas (Draft/Submit/Review)

**User Story:** Sebagai Katimker/Kabid, saya ingin alur draft, submit, dan review
tugas tetap berjalan setelah perubahan role.

- 9.1 THE SYSTEM SHALL memastikan Katimker aktif ber-role `katimker` tetap dapat
  membuat draft, submit, mengunggah bukti, dan mengedit tugas (perilaku
  `Katimker/TaskController` tidak berubah).
- 9.2 THE SYSTEM SHALL memastikan Kabid aktif ber-role `kabid` tetap dapat
  melihat dan menilai (approve/reject/ubah jenis) tugas binaannya
  (`Kabid/ReviewController`).
- 9.3 THE SYSTEM SHALL memastikan otorisasi berbasis `ApproverMapping::kabidFor`
  tetap konsisten memakai `sikep_user_id`.

### 10. Pembaruan Test & Factory

**User Story:** Sebagai pengembang, saya ingin factory dan test mencerminkan
role lokal, bukan role SIKEP.

- 10.1 THE SYSTEM SHALL memperbarui `UserFactory` agar mendukung state `role` dan
  `aktif` (mis. state `admin()`, `katimker()`, `kabid()`, `nonaktif()`), menjaga
  invarian 1.5.
- 10.2 THE SYSTEM SHALL memperbarui test yang bergantung pada role turunan SIKEP
  (mis. `RoleResolverTest`, `ApproverMappingTest`) agar memakai role lokal.
- 10.3 THE SYSTEM SHALL menambah test untuk gerbang login (2.2–2.5), toggle
  aktif + anti-lockout (4.5–4.6), penegakan sesi (7.1), dan validasi
  ApproverMapping berbasis role (5.3).

---

## Non-Functional Requirements

- NFR1. Pesan penolakan/validasi yang terlihat pengguna harus berbahasa
  Indonesia dan menjelaskan alasan dengan jelas.
- NFR2. Perubahan role/aktif harus auditable (jejak aktor + nilai lama→baru) agar
  dapat ditelusuri.
- NFR3. Record user tidak boleh dihapus saat dinonaktifkan (retensi untuk audit).
- NFR4. Migrasi harus idempotent/aman dijalankan pada data produksi yang sudah
  berisi user, dan menyediakan `down()`.
- NFR5. Resolusi role tidak boleh melakukan query SIKEP di jalur panas (login,
  per-request middleware) setelah lepas dari SIKEP.

---

## Acceptance Criteria

1. Migrasi menambahkan `role` (nullable) dan `aktif` (default true) ke `users`,
   mempertahankan `is_admin`, dan `down()` menghapus hanya kedua kolom baru.
2. Setelah backfill, setiap user `is_admin = true` memiliki `role = 'admin'` dan
   `aktif = true`; user lain yang tak terpetakan memiliki `role = null`.
3. Login ditolak dengan pesan Indonesia spesifik ketika: tidak ada record lokal,
   `aktif = false`, atau `role = null`; masing-masing menghasilkan pesan berbeda
   yang sesuai (2.2/2.3/2.4).
4. Login berhasil hanya ketika record lokal `aktif = true` dan `role` sah; role
   efektif berasal dari kolom `role` lokal, bukan jabatan SIKEP.
5. Verifikasi password tetap melalui SIKEP; kegagalan verifikasi menghasilkan
   pesan generik, dan SIKEP down menghasilkan pesan "Integrasi SIKEP tidak
   tersedia saat ini."
6. Admin dapat menarik pegawai dari SIKEP dan menetapkan role saat menarik; role
   di luar domain ditolak oleh validasi.
7. Admin dapat mengubah role dan toggle `aktif`; setiap perubahan tercatat di
   audit dengan nilai lama→baru dan identitas aktor.
8. Admin tidak dapat menonaktifkan atau menurunkan role dirinya sendiri; tindakan
   yang menyisakan nol admin aktif ditolak.
9. Menonaktifkan user tidak menghapus recordnya.
10. Daftar kandidat Katimker dan Kabid di ApproverMapping berasal dari user lokal
    ber-role `katimker`/`kabid` yang `aktif = true`; pemetaan dengan kandidat tak
    valid ditolak dengan pesan Indonesia yang jelas.
11. `ApproverMapping` tetap memakai `sikep_user_id` dan strukturnya tidak berubah.
12. `php artisan app:set-admin {login}` menetapkan `role=admin`, `is_admin=true`,
    `aktif=true`; `--off` mencabut admin dan menetapkan role resultan yang
    terdefinisi (invarian 1.5 terjaga).
13. User dengan `aktif = false` (atau `role` menjadi null) di tengah sesi di-logout
    pada request berikutnya dengan pesan Indonesia yang jelas.
14. `RoleResolver`/`User::roles()` menurunkan role dari kolom lokal; konstanta
    `JABATAN_*` dan helper `isKatimker()/isKabid()` tetap ada tetapi tidak dipakai
    di jalur role.
15. Katimker aktif tetap bisa draft/submit/edit/unggah bukti; Kabid aktif tetap
    bisa review (approve/reject/ubah jenis) binaannya — tidak ada regresi.
16. `UserFactory` mendukung state `role`/`aktif`; test yang bergantung role SIKEP
    diperbarui, dan test baru untuk gerbang login, anti-lockout, penegakan sesi,
    serta validasi ApproverMapping lulus.
17. `php artisan test` (atau runner proyek) hijau untuk suite yang terdampak.

---

## Out of Scope

- Perubahan selain menyentuh file upload bukti (multi-file, foto+dokumen
  bersamaan, melihat bukti saat edit) yang disebut di pesan awal pengguna —
  itu ditangani alur tugas terpisah; di sini hanya dijamin **non-regresi**.
- Role lebih dari satu per user (dikonfirmasi: 1 user = 1 role).
- Opsi penyimpanan role selain 3a (kolom `role` + `aktif` di `users`).
- Perombakan tabel/model `ApproverMapping` atau penggantian kunci `sikep_user_id`.
- Penghapusan konstanta jabatan SIKEP atau helper `isKatimker()/isKabid()`
  (dipertahankan untuk laporan masa depan).
- Laporan/analitik baru yang memakai jabatan SIKEP (hanya memastikan konstanta
  tetap tersedia).
