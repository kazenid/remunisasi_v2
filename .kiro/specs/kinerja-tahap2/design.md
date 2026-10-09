# Design — Kinerja Tahap 2 (Fitur Lanjutan)

## Overview

Empat fitur di atas fondasi MVP, tanpa mesin pembayaran insentif. Mengikuti `requirements.md` dan pola yang sudah mapan (service + policy + Inertia/Vue + skill `sakai-ui-ux`).

## 1. Komponen kehadiran dari SIKEP

### Model read-only tambahan (koneksi `sikep`)
- `SikepAbsensiDetail` (`absensi_detail`) — baris harian.
- `SikepScan` (`absensi_excel_detail`) — scan masuk/keluar by `no_absen`.
- `SikepCuti` (`cuti`), `SikepCutiDetail` (`cuti_detail`), `SikepMsCuti` (`ms_cuti`).
- `SikepJamKerja` (`ms_jam_kerja`), `SikepPenguranganInsentif` (`ms_pengurangan_insentif`).

### `AttendanceService` (ditulis ulang dari versi MVP)
Metode `nilai(sikepUserId, noAbsen, tahun, bulan): array` mereplikasi `setting_absensi_rekap.php`:

```
untuk tiap tanggal 1..akhir bulan:
  jika ada cuti approved pada tgl:
     kategori = Sakit(3) | DL(6) | Cuti umum(4)   → pengurangan += prosentase kategori
  else:
     ambil jadwal (absensi_detail → ms_jam_kerja) untuk tgl
     jika libur=1: lewati (hari libur)
     else:
        scan_masuk, scan_keluar dari absensi_excel_detail (window jam diijinkan)
        terlambat → prosentase berjenjang (kategori Reguler, by menit)
        pulang awal → prosentase berjenjang
        tidak scan satu sisi → kategori "Tidak Scan"
        tidak scan dua sisi → kategori "TK"
hasil:
  total_pengurangan (float)
  persen_kehadiran = max(0, 100 - total_pengurangan)
  rincian: hadir, terlambat, pulang_awal, libur, cuti, tanpa_keterangan, hari_efektif
```

Konstanta jam diizinkan absen (dari `koneksi.php`): mulai = 3 jam sebelum, akhir = 6 jam sesudah. Dibuat konfigurabel di `config/sikep.php`.

**Verifikasi:** bandingkan `total_pengurangan` service dengan kolom "Pengurangan Insentif" laporan SIKEP untuk 1-2 pegawai nyata pada periode yang sudah berjalan. Selisih harus nol (atau dijelaskan).

Dipakai di halaman Rekap (menggantikan `summary()` lama) sebagai komponen kehadiran + rincian, dengan label persiapan.

## 2. Pemindahan klasifikasi (utama ↔ tambahan)

### Perubahan
- `ReviewService::ubahJenis(TaskReport $t, string $jenis, string $alasan, int $aktor)` — guard status `diajukan`, jenis valid, alasan wajib; audit `task.reclassify` (jenis dari→ke).
- `ReviewService::approve(...)` menerima parameter opsional `jenis` + `alasanJenis`; bila berbeda dari jenis sekarang, panggil pemindahan sebelum menyetujui (satu transaksi, dua entri audit: reclassify lalu approve).
- Policy: pakai `TaskReportPolicy::review` (Kabid snapshot + status diajukan) yang sudah ada.

### UI (`Kabid/ReviewDetail`)
- Tambah kontrol jenis (SelectButton utama/tambahan) + field alasan yang muncul saat jenis diubah.
- Dua jalur: tombol "Ubah Jenis" (aksi terpisah) dan penyertaan jenis saat "Setujui".

## 3. Penugasan tambahan oleh Kabid

### Perubahan model `task_reports`
- Kolom baru:
  - `dibuat_oleh_sikep_user_id` (nullable) — pembuat; null = dibuat Katimker sendiri, berisi id Kabid bila penugasan.
  - `jenis_terkunci` (boolean, default false) — true untuk penugasan Kabid; Katimker tak boleh ubah jenis.
- Status baru pada enum: `menunggu_bukti` (sebelum `draf`). Tugas penugasan mulai di sini.

### State machine (penambahan)
```
(Kabid buat) → menunggu_bukti ──(Katimker lengkapi+ajukan)──▶ diajukan ──▶ disetujui/ditolak
                     ▲                                            │
                     └──────────── (ditolak, revisi) ────────────┘  (kembali ke alur edit biasa)
```
- `menunggu_bukti` diperlakukan seperti `draf` untuk editabilitas Katimker (isi bukti/durasi/uraian) KECUALI jenis terkunci.

### Services / Controller
- `AssignmentService::createForKatimker(kabidSikepUserId, katimkerSikepUserId, data)` — validasi Kabid dipetakan ke Katimker (atau Admin), buat tugas jenis `tambahan`, `jenis_terkunci=true`, status `menunggu_bukti`, `kabid_sikep_user_id` di-snapshot; audit `task.assign`.
- `ReviewController` (Kabid) + halaman untuk membuat penugasan (pilih Katimker binaan, judul, uraian, target opsional).
- `TaskReportPolicy::update` disesuaikan: Katimker boleh edit saat `menunggu_bukti`/`draf`/`ditolak`; perubahan `jenis` ditolak bila `jenis_terkunci` dan aktor bukan Kabid/Admin.
- `TaskService::submit` menerima status awal `menunggu_bukti` sebagai sumber transisi ke `diajukan`.

## 4. Target global & per individu

### Target global
- Dipindah/diselaraskan dari `incentive_configs` yang sudah ada (`target_utama_menit`, `target_tambahan_menit`). Tetap di sana (lingkup global).

### `individual_targets` (tabel baru, DB lokal)
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | PK | |
| katimker_sikep_user_id | unsigned int, index | |
| tahun | smallint, nullable | null = berlaku umum utk individu |
| bulan | tinyint, nullable | |
| target_utama_menit | unsigned int, nullable | |
| target_tambahan_menit | unsigned int, nullable | |
| di_set_oleh | unsigned int, nullable | sikep_user_id |
| timestamps | | |
| unik (katimker_sikep_user_id, tahun, bulan) | | |

### `TargetResolver`
- `efektif(katimkerSikepUserId, tahun, bulan): {utama_menit, tambahan_menit, sumber}` — cari override individu (periode spesifik → umum individu) lalu fallback ke global `IncentiveConfig`.

### Otorisasi & UI
- Target global: Admin (halaman Konfigurasi Insentif yang sudah ada).
- Override individu: Admin (di Manajemen/halaman khusus) atau Kabid untuk Katimker binaannya (dari daftar binaan). Audit `target.update`.

## Otorisasi (ringkas)
- Reclassify & assignment: `TaskReportPolicy::review` / cek pemetaan di service.
- Target individu: Admin global; Kabid hanya untuk Katimker yang dipetakan.

## Testing
- Unit: `AttendanceService` (vektor dari data nyata), `TargetResolver` (individu>global, periodik), transisi `menunggu_bukti`.
- Feature: reclassify (saat approve & terpisah, alasan wajib, guard), assignment (buat, hanya kabid pembina/admin, Katimker isi bukti & ajukan, jenis terkunci), target (set global/individu, resolusi, otorisasi), non-regresi suite.

## Catatan ditunda (Tahap 2 lanjutan)
Mesin perhitungan insentif final: capaian per komponen (cap 100%), formula bobot, nominal × persentase, pembulatan, penanda final vs simulasi. Menunggu parameter kebijakan RSUD.
