# Dokumentasi Proses Bisnis Remunisasi

Dokumen ini menjelaskan alur, sumber data, dan implementasi teknis pada aplikasi Remunisasi RSUD Bangil. Dokumen diselaraskan dengan kode terkini, migrasi per Oktober 2026, dan Perdir RSUD Bangil No. 186 Tahun 2026.

## 1. Ringkasan alur

1. **Autentikasi**: Pegawai login dengan kredensial SIKEP; aplikasi menetapkan role struktural dan menyinkronkan profil ke database lokal.
2. **Pengisian Kinerja**: Pegawai mengisi tugas harian sebagai draft atau mengajukannya dengan kategori (`utama` / `tambahan`), durasi jam (0,25–24 jam), deskripsi, serta bukti opsional (foto dan koordinat GPS).
3. **Verifikasi Kinerja**:
   - **Staf Biasa (Alur 4 Tingkat)**: Diajukan bertingkat melalui Katker → Kabid → Wadir → Direktur.
   - **Ketua Tim Kerja / Katimker (Alur 1 Tingkat - Perdir 186/2026)**: Pegawai bertanda `is_katimker` diajukan langsung ke Atasan Langsung (`atasan_langsung_id`) dengan level `atasan_katimker`. Persetujuan atasan langsung langsung menyelesaikan verifikasi (`disetujui_atasan_katimker` → selesai).
   - Approver dapat menyetujui, meminta revisi, menolak, serta mengoreksi kategori tugas atau jam yang disetujui.
4. **Rekapitulasi & Insentif**: Dihitung otomatis dari persentase kehadiran SIKEP dan total jam tugas yang telah disetujui setidaknya satu tingkat.

## 2. Sumber data utama

| Data | Sumber | Keterangan |
|---|---|---|
| Identitas/login | SIKEP `ms_user` | Dicari via `username`, `no_absen`, atau `no_pegawai`; profil disalin ke `users` saat login berhasil. |
| Unit Kerja | SIKEP `ms_unit_user` → `ms_unit` | `id_unit`, `nama_unit`; fallback nama menggunakan `unit_ruang`. |
| Role Struktural | SIKEP `ms_user.su`, `ms_khusus`, `ms_kabid_unit`, `ms_group_user`/`ms_group`, `unit_sipenilai` | `admin`, `direktur`, `wadir`, `kabid`, `katker`, `staf`. |
| Penugasan Katimker | DB lokal `users` (`is_katimker`, `atasan_langsung_id`) | Dikelola Admin melalui menu Manajemen Katimker; bukan role SIKEP melainkan relasi atasan langsung. |
| Kehadiran | SIKEP `absensi`, `absensi_detail` | Dihitung dari rasio hari ber-`jam_masukx` atau `total_jam_kerja` terhadap jumlah detail hari kerja. |
| Periode/target/bobot | DB aplikasi `periode_kinerja` | Dikelola via modul periode atau digenerate otomatis bulanan. |
| Tugas/approval/bukti | DB aplikasi `tugas_harian`, `persetujuan_kinerja`, `tugas_bukti` | File bukti di storage disk `public`; riwayat approval tersimpan per tindakan. |
| Hasil rekap | DB aplikasi `rekap_insentif` | Satu rekap per user-periode, dihitung ulang otomatis secara upsert. |

Koneksi SIKEP dikonfigurasi di `config/database.php` (`DB_SIKEP_*`); database aplikasi menggunakan `DB_*`.

## 3. Login, role, dan penetapan Katimker

`LoginController` memvalidasi kredensial via `SikepAuthService`. Saat otentikasi sukses, data pengguna disinkronkan ke tabel `users`.

Urutan resolusi role (`SikepAuthService::resolveRole`):
1. `ms_user.su = 1` → `admin`.
2. Terdaftar di `ms_khusus` → `direktur`, `wadir`, atau `kabid`.
3. Terdaftar di `ms_kabid_unit` → `kabid`.
4. Group ID 1 atau 2 → `admin`.
5. Group ID 5, 6, atau 7 → `katker`.
6. Terdaftar di `unit_sipenilai` → `katker`.
7. Selain itu → `staf`.

**Penetapan Katimker**:
- Admin dapat menetapkan pegawai sebagai Katimker serta memilih atasan langsungnya via `/admin/katimker`.
- `User::hasKatimkerStaff()` memberikan hak akses verifikasi kepada user mana pun yang menjadi atasan langsung staf Katimker.

## 4. Periode dan parameter

Tabel `periode_kinerja` memuat target jam, bobot persentase, nominal dasar, dan status (`draft`, `aktif`, `terkunci`, `selesai`):
- Standar default: Target tugas utama 150 jam, tugas tambahan 15 jam.
- Bobot: Kehadiran 40%, Tugas Utama 50%, Tugas Tambahan 10%.
- Nominal dasar: Rp10.000.000 (dapat disesuaikan per periode).

## 5. Input tugas harian

- Endpoint: `/tugas` (Auth required).
- Validasi: tanggal, kategori (`utama` / `tambahan`), judul, deskripsi, durasi (0,25–24 jam), file bukti opsional (maks 10 MB), dan koordinat GPS opsional.
- **Penentuan Level Awal (`ApprovalService::initialLevelFor`)**:
  - Jika pemilik tugas adalah Katimker (`is_katimker = true` dan memiliki `atasan_langsung_id`), level awal adalah `atasan_katimker`.
  - Jika staf biasa, level awal adalah `katker`.
- Status tugas: `draft`, `diajukan`, `disetujui_katker`, `disetujui_kabid`, `disetujui_wadir`, `disetujui_direktur`, `disetujui_atasan_katimker`, `revisi`, `ditolak`.

## 6. Alur persetujuan (Approval)

Hak akses verifikasi terbuka untuk:
1. Role approver struktural (`katker`, `kabid`, `wadir`, `direktur`, `admin`).
2. Pejabat/pegawai yang menjadi atasan langsung staf Katimker (`isAtasanKatimker`).

### Matriks Alur Persetujuan:

| Jalur | Level Saat Ini | Verifikator | Status Setelah Setuju | Level Berikutnya |
|---|---|---|---|---|
| **Katimker** | `atasan_katimker` | Atasan Langsung (`atasan_langsung_id`) | `disetujui_atasan_katimker` | `selesai` |
| **Staf** | `katker` | Kepala Ruang / Tim Kerja unit | `disetujui_katker` | `kabid` |
| **Staf** | `kabid` | Kepala Bidang | `disetujui_kabid` | `wadir` |
| **Staf** | `wadir` | Wakil Direktur | `disetujui_wadir` | `direktur` |
| **Staf** | `direktur` | Direktur / Admin | `disetujui_direktur` | `selesai` |

Approver dapat:
- Menyetujui langsung atau mengubah jam yang diakui (`jam_disetujui`).
- Mengubah kategori tugas (`utama` ↔ `tambahan`).
- Meminta revisi (kembali ke pegawai untuk diperbaiki).
- Menolak tugas (proses berhenti permanen).
- Bulk approve untuk menyetujui beberapa tugas sekaligus.

Setiap tindakan dicatat ke `persetujuan_kinerja` dan memicu kalkulasi ulang insentif pegawai bersangkutan.

## 7. Perhitungan rekap dan insentif

`InsentifCalculatorService` menghitung nilai insentif:
1. **Kehadiran**: Rasio hari kerja efektif dari absensi SIKEP (fallback 100% jika data absensi kosong).
2. **Jam Disetujui**: Menjumlahkan `jam_disetujui` dari tugas berstatus `disetujui_katker`, `disetujui_kabid`, `disetujui_wadir`, `disetujui_direktur`, atau `disetujui_atasan_katimker`.
3. **Rumus Perhitungan**:
   ```text
   capaian_utama     = min(100, jam_utama_disetujui / target_jam_utama × 100)
   capaian_tambahan  = min(100, jam_tambahan_disetujui / target_jam_tambahan × 100)
   skor_kehadiran    = persentase_kehadiran × bobot_kehadiran / 100
   skor_utama        = capaian_utama × bobot_tugas_utama / 100
   skor_tambahan     = capaian_tambahan × bobot_tugas_tambahan / 100
   total_kinerja     = min(100, skor_kehadiran + skor_utama + skor_tambahan)
   nominal_insentif  = nominal_dasar × total_kinerja / 100
   ```
4. Hasil disimpan secara upsert ke `rekap_insentif`.

Kalkulasi berjalan saat dashboard/halaman tugas dibuka, setelah approval, dan melalui endpoint hitung ulang semua pegawai aktif. Kolom status rekap (`draft`, `dikunci`, `cair`) tidak diubah otomatis oleh kalkulator; nominal merupakan hasil rekap/simulasi.

## 8. Standar Antarmuka & Dialog Modal

Untuk menjaga konsistensi tampilan dan mencegah kebocoran transparansi pada PrimeVue Aura Theme v4:
- **Background Solid**: Token CSS global di `resources/css/app.css` menetapkan background solid opaque (`#ffffff` light, `#111827` dark) pada `.p-dialog`, `.p-dialog-header`, `.p-dialog-content`, dan `.p-dialog-footer`.
- **Mask & Backdrop**: `.p-dialog-mask` menggunakan latar transparan gelap ber-blur (`rgba(15, 23, 42, 0.5)` light, `rgba(0, 0, 0, 0.72)` dark) dengan padding pengaman `1rem` (mobile: `0.75rem`).
- **Batas Viewport Responsif**: Seluruh modal dialog dibatasi `max-width: calc(100vw - 2rem)` dan `max-height: calc(100dvh - 2rem)` agar tidak terpotong atau keluar dari layar mobile.
- **Form Layout**: Form input dialog menggunakan scroll internal terpisah (`overflow-y: auto`) dan tombol aksi tetap terlihat di bagian footer.

## 9. Endpoint aplikasi

Semua route bisnis dilindungi middleware `auth` di `routes/web.php`:

- **Dashboard**: `GET /` — ringkasan kinerja dan capaian pribadi.
- **Tugas Harian**: `GET|POST|PUT|DELETE /tugas` — manajemen log tugas harian.
- **Approval**:
  - `GET /approval` — antrean verifikasi sesuai hak akses.
  - `POST /approval/{tugas}/approve` — persetujuan tugas.
  - `POST /approval/{tugas}/revisi` — permintaan revisi tugas.
  - `POST /approval/{tugas}/tolak` — penolakan tugas.
  - `POST /approval/bulk` — persetujuan massal.
- **Manajemen Katimker (Admin)**:
  - `GET /admin/katimker` — daftar Katimker dan atasannya.
  - `GET /admin/katimker/candidates` — pencarian kandidat pegawai/atasan.
  - `PUT /admin/katimker/{user}` — perbarui status Katimker & atasan langsung.
  - `POST /admin/katimker/bulk-assign` — penetapan massal atasan langsung.
- **Rekapitulasi**:
  - `GET /rekap` — tampilan rekap insentif.
  - `POST /rekap/{periode}/calculate` — hitung ulang rekap semua pegawai aktif.
- **Periode Kinerja**: `GET|POST|PUT /periode` — konfigurasi periode & target.

Route UIKit, produk, dan demonstrasi template berada di luar modul bisnis remunisasi inti.

## 10. Batas implementasi

- Perilaku aktual mengikuti controller, service, model, dan migration yang ada.
- Tidak ada master kategori/tugas pokok-fungsi statis; pegawai memilih kategori (`utama` / `tambahan`) dan approver dapat mengoreksinya saat persetujuan.
- Foto dan koordinat GPS bersifat opsional pada validasi backend.
- Belum ada cron job terjadwal otomatis untuk penutupan cut-off atau pencairan payroll ke sistem perbankan. Profil disinkronkan saat login.
- Absensi yang tidak ditemukan di DB SIKEP default 100% sebagai fallback toleransi.

## 11. Rujukan kode

- Route: `routes/web.php`
- Autentikasi SIKEP: `app/Http/Controllers/Auth/LoginController.php`, `app/Services/SikepAuthService.php`, `config/database.php`
- Manajemen Katimker: `app/Http/Controllers/Admin/KatimkerManagementController.php`, `resources/js/Pages/Admin/Katimker/Index.vue`
- Tugas Harian: `app/Http/Controllers/TugasHarianController.php`, `app/Models/TugasHarian.php`, `resources/js/Pages/Tugas/Index.vue`
- Verifikasi & Approval: `app/Http/Controllers/ApprovalController.php`, `app/Services/ApprovalService.php`, `app/Models/PersetujuanKinerja.php`, `resources/js/Pages/Approval/Index.vue`
- Kalkulasi Insentif: `app/Services/InsentifCalculatorService.php`, `app/Http/Controllers/RekapInsentifController.php`, `app/Models/RekapInsentif.php`
- Periode Kinerja: `app/Http/Controllers/PeriodeKinerjaController.php`, `app/Models/PeriodeKinerja.php`, `resources/js/Pages/Periode/Index.vue`
- Style & Dialog System: `resources/css/app.css`
- Regulasi Terkait: `Perdir_RSUD_Bangil_186_2026.md`
- Pengujian Otomatis: `tests/Feature/KatimkerApprovalTest.php`, `tests/Feature/RemunisasiWorkflowTest.php`
