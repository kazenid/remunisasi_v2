# Verifikasi — Role Manual (Lepas Role dari SIKEP)

Iterasi pertama (review.json tidak ada). Implementasi mengikuti `plan.md` +
`design.md`, dengan keputusan pemilik **Opsi A** (backfill non-admin lama hanya
`role=null`, `aktif` DIBIARKAN `true`). Semua perintah dijalankan di
`f:\LARAVEL\remunisasi_v2` (PowerShell/Windows).

## Ringkasan hasil

| Gerbang kualitas | Perintah | Hasil |
| ---------------- | -------- | ----- |
| Lint PHP (file berubah) | `vendor/bin/pint <file...>` | PASS (13 files) |
| Suite test lengkap | `php artisan test` | **143 passed (539 assertions)** |
| Build front-end | `npm run build` | SUKSES (`✓ built in 5.80s`) |
| Route | `php artisan route:list --name=admin.pengguna` | `tarik/role/aktif` ada; `set-admin` TIDAK ada |
| Migrasi | up() via RefreshDatabase (tiap test) + roundtrip sqlite | OK |

Catatan: `vendor/bin/pint --test` menyisakan 3 pelanggaran gaya di file yang
TIDAK disentuh perubahan ini (`app/Http/Controllers/ProductController.php`,
`app/Models/Product.php`, `database/seeders/DatabaseSeeder.php`) — pra-ada,
di luar lingkup. Semua file yang diubah pada tugas ini lolos pint.

## Detail verifikasi test (sesuai daftar WAJIB)

Seluruh suite hijau. Pemetaan butir verifikasi → test:

- (a) Login ditolak dengan pesan berbeda untuk belum-ditarik / nonaktif / tanpa-role
  — `Tests\Feature\LoginTest`:
  `login_tanpa_record_lokal_ditolak` (pesan "belum terdaftar/diaktifkan"),
  `login_record_nonaktif_ditolak` (pesan "dinonaktifkan"),
  `login_record_tanpa_peran_ditolak` (pesan "belum memiliki peran").
- (b) Login sukses untuk user aktif ber-role — `LoginTest::login_sukses_dengan_record_lokal_aktif_berperan`.
- (c) Celah pending-2FA tertutup — `Tests\Feature\TwoFactorAuthTest::verify_challenge_user_dinonaktifkan_saat_pending_ditolak`.
- (d) EnsureActiveRole melogout user dinonaktifkan di tengah sesi —
  `Tests\Feature\EnsureActiveRoleTest` (4 kasus: nonaktif, role=null, dashboard
  tanpa `role:`, request Inertia GET → 409 + errors.login).
- (e) Tarik pegawai + tetapkan role → record users aktif ber-role —
  `Tests\Feature\TarikPegawaiTest` (sukses katimker/admin, pegawai tak ditemukan,
  role di luar domain) + guard "sudah ditarik" di `AdminManagementTest`.
- (f) ubahRole & toggleAktif + guard anti-lockout —
  `Tests\Feature\AdminManagementTest` (menjadikan admin, tak bisa turunkan peran
  diri sendiri, tak bisa nonaktifkan diri sendiri, nonaktifkan user lain, props baru).
- (g) Backfill Opsi A — `Tests\Feature\BackfillBootstrapAdminTest`
  (non-admin lama → `role=null`, `aktif=true`; admin lama → `role=admin, aktif=true`;
  bootstrap admin via command bisa login; `--off` → `role=null` tanpa ubah aktif).
- (h) ApproverMapping berbasis role lokal + pemetaan field error —
  `Tests\Feature\ApproverMappingTest`
  (`target_bukan_kabid_aktif_error_pada_field_kabid`,
  `sumber_bukan_katimker_aktif_error_pada_field_katimker`,
  `list_katimker_menandai_kabid_tidak_valid`, `assign_menolak_target_bukan_kabid_aktif`).
- (i) `app:set-admin` → `role=admin, aktif=true`; `--off` → `role=null` —
  `BackfillBootstrapAdminTest::bootstrap_admin_via_command_bisa_login` &
  `set_admin_off_mencabut_role_tanpa_mengubah_aktif`.
- (j) Non-regresi alur tugas per peran (NON-MOCK) —
  `Tests\Feature\RoleManualNonRegressionTest` (katimker nyata submit; kabid nyata
  approve) + suite mock lama (`KatimkerSubmitTest`, `KabidReviewTest`,
  `ReclassifyTest`, `RevisiCycleTest`, `AssignmentTest`) tetap hijau.

Unit: `Tests\Unit\RoleResolverTest` (admin/katimker/nonaktif/role-null/tidak-ada)
dan `Tests\Unit\UserTest` (invarian `setRole`).

## Verifikasi manual (tidak ada test runner komponen Vue di proyek)

Perubahan `resources/js/Pages/Admin/Pengguna.vue` dan
`resources/js/Pages/Admin/ApproverMapping.vue` diverifikasi lewat `npm run build`
(kompilasi sukses) dan tinjauan props:
- Pengguna.vue: kolom Peran (`Tag` dari `role`), Status (`Tag` Aktif/Nonaktif),
  dropdown ubah role + simpan (`admin.pengguna.role`), toggle aktif via
  `useConfirm` (`admin.pengguna.aktif`), dialog tarik + pemilihan role
  (`admin.pengguna.tarik`), tombol Tarik di-`disabled` + tooltip untuk
  `sudah_ditarik`. Teks "Peran ditetapkan manual oleh administrator."
- ApproverMapping.vue: badge `Tag` "bukan Kabid aktif" (severity warn) saat
  `kabid_valid === false`.

## Catatan Opsi A

`design.md` §1 masih menuliskan backfill non-admin `aktif=false`. Keputusan
pemilik mengunci **Opsi A**: migrasi `up()` hanya menyetel `role=null` untuk
non-admin lama dan TIDAK menyentuh `aktif` (tetap default `true`). Koreksi ini
sudah tercatat di `plan.md` (bagian "KOREKSI KEPUTUSAN PEMILIK"). Test (g)
menegaskan non-admin lama tetap `aktif=true`.
