# Keputusan Desain MVP — Remunisasi v2 (Katimker → Kabid)

**Status:** Draf hasil brainstorming
**Tanggal:** 6 Oktober 2026
**Ruang lingkup:** MVP alur input tugas oleh Katimker dan penilaian per-tugas oleh Kabid.

Dokumen ini merekam keputusan yang sudah disepakati dalam diskusi. Menjadi acuan sebelum penulisan kode. Hal yang masih perlu diverifikasi ke basis data SIKEP ditandai dengan **(verifikasi)**.

---

## 1. Konteks Aplikasi

Remunisasi v2 adalah ekstensi dari **SIKEP** (aplikasi kepegawaian PHP native, MySQL, DB `bangil_kepegawaian`). SIKEP tetap menjadi sumber kebenaran untuk pegawai, unit, jabatan, struktur, dan absensi. Remunisasi v2 fokus pada pencatatan kinerja harian dan penilaian jam untuk perhitungan insentif ("Si Pintar Gais", Perdir RSUD Bangil No. 186/2026).

- Stack: Laravel 13 + Inertia + Vue 3 + PrimeVue.
- Fokus MVP: dua peran — **Katimker** (input tugas dirinya sendiri) dan **Kabid** (menilai per-tugas: approve/tolak + catatan).

---

## 2. Integrasi Data: Dua Koneksi Database

| Koneksi | Database | Akses | Isi |
|---|---|---|---|
| `mysql` (default) | `remunisasi_v2` (localhost) | baca–tulis | laporan tugas, jam diajukan/disetujui, bukti, keputusan, audit, konfigurasi, pemetaan approver, sesi/user lokal |
| `sikep` | `bangil_kepegawaian` (10.20.1.116:2651) | **read-only** | pegawai, unit, jabatan, absensi, libur |

**Aturan keras:** koneksi `sikep` tidak pernah dipakai untuk operasi tulis apa pun (tidak di migration, tidak di seeder, tidak di runtime). Model yang membaca SIKEP menetapkan `protected $connection = 'sikep'` dan tidak memiliki jalur `save()/update()/delete()`.

### 2.1 Pemetaan sumber data SIKEP

| Kebutuhan | Tabel SIKEP | Catatan |
|---|---|---|
| Identitas & login | `ms_user` | kolom kunci: `id`, `username`, `no_absen`, `password`, `nama`, `gelar_depan`, `gelar_belakang`, `aktif`, `token_pegawai` |
| Role Katimker | `ms_jabatan_lapangan_user` (`id_jabatan_lapangan = 3` "Kepala Tim") | alternatif/penguat: `ms_group_user` group id 7 "Ka. Tim" |
| Role Kabid | `ms_jabatan_lapangan_user` (`id_jabatan_lapangan IN (10,11,12)`) | 10=Pelayanan Medik, 11=Keperawatan, 12=Penunjang |
| Unit pegawai | `ms_unit_user` → `ms_unit` | `ms_unit` flat (tanpa kolom bidang/parent) **(verifikasi)** |
| Absensi harian | `absensi` + `absensi_detail` | kehadiran, terlambat, pulang cepat, cuti, libur per tanggal |
| Hari libur nasional | `ms_libur_nasional` | |

### 2.2 Autentikasi

Login memakai kredensial SIKEP terhadap `ms_user` (read-only). Verifikasi password mereplikasi algoritma SIKEP:

```
password_enc = md5(pengacak_pass_1 . md5(password) . pengacak_pass_2)
```

Pengacak diambil dari konfigurasi (disalin dari `koneksi.php` SIKEP, disimpan di `.env`, bukan hardcode). Login menerima `username` **atau** `no_absen`, dan hanya untuk `aktif = 1`.

**Tidak direplikasi:** master password hardcode (`sdmjaya`, `kiduldalem`) yang ada di SIKEP. Backdoor ini sengaja tidak dibawa ke remunisasi v2 (risiko keamanan).

Setelah login sukses, remunisasi v2 membuat/menyinkronkan **user lokal** (mirror ringan: `sikep_user_id`, `username`, `nama`, peran turunan) untuk sesi, otorisasi, dan relasi ke data lokal. Password tidak disalin ke DB lokal.

---

## 3. Peran & Otorisasi (MVP)

Peran diturunkan dari jabatan lapangan SIKEP, dapat ditimpa manual (lihat §6).

| Peran | Asal (default) | Kemampuan di MVP |
|---|---|---|
| Katimker | `ms_jabatan_lapangan_user` id 3 | buat/ubah tugas sendiri (draf), ajukan, lihat status & catatan, revisi, lihat rekap pribadi |
| Kabid | `ms_jabatan_lapangan_user` id 10/11/12 | lihat tugas Katimker binaannya, approve/tolak per-tugas dengan catatan, set jam disetujui |
| Admin | ditetapkan manual (lokal) | kelola pemetaan approver, periode, konfigurasi; lihat audit |

---

## 4. Model Data Lokal (DB `remunisasi_v2`)

Ringkasan entity inti MVP. Detail kolom final ditetapkan saat migration.

### 4.1 `users` (lokal, mirror SIKEP)
- `id`, `sikep_user_id` (unik), `username`, `nama`, `no_absen`
- `peran` turunan (bukan disimpan permanen kalau bisa dihitung; untuk MVP boleh di-cache)
- timestamp sinkronisasi terakhir

### 4.2 `task_reports` (laporan/entri tugas)
Satu baris = satu tugas pada satu tanggal.
- `id`
- `sikep_user_id` — pemilik (Katimker)
- `tanggal` — tanggal kegiatan
- `jenis` — enum: `utama` | `tambahan`
- `judul`, `uraian` (opsional)
- `durasi_diajukan_menit` — integer menit (input jam+menit → menit)
- `durasi_disetujui_menit` — integer menit, diisi Kabid (0 ≤ disetujui ≤ diajukan)
- `status` — lihat §5
- `kabid_sikep_user_id` — penilai yang menangani (dari pemetaan)
- `catatan_penilai` — catatan approve/tolak
- `alasan` wajib bila jam dikurangi/0 atau ditolak
- `diajukan_pada`, `dinilai_pada`
- `lat`, `lng`, `akurasi` (opsional, koordinat bukti)
- timestamps

### 4.3 `task_attachments` (bukti)
- `id`, `task_report_id`
- `tipe` — enum: `foto_selfie` | `dokumen`
- `path`, `nama_asli`, `mime`, `ukuran`
- `diambil_pada`
- Aturan: minimal satu `foto_selfie` **wajib** per tugas sebelum bisa diajukan. `dokumen` opsional.

### 4.4 `approver_mappings` (pemetaan Katimker → Kabid)
- `id`, `katimker_sikep_user_id` (unik — satu Katimker tepat satu Kabid), `kabid_sikep_user_id`
- `aktif`, `berlaku_mulai` (opsional, untuk perubahan efektif)
- timestamps + user yang mengubah (audit)
- Relasi **one-to-one**: `katimker_sikep_user_id` unik. Seluruh pemetaan **ditetapkan manual oleh admin** (tidak diturunkan dari SIKEP).

### 4.5 `periods` (periode)
- `id`, `tahun`, `bulan`
- `cut_off` (default tgl 10), `target_cair` (default tgl 15)
- `status` — `terbuka` | `terkunci`
- Catatan: cut-off/penguncian masuk fase lanjutan; untuk MVP cukup tersimpan sebagai konfigurasi.

### 4.6 `audit_logs`
- Mencatat perubahan status, jam disetujui, keputusan, dan perubahan pemetaan approver.

> Perhitungan insentif (bobot 40/50/10) **tidak** masuk MVP ini. MVP berhenti di rekap jam disetujui per jenis tugas. Kalkulasi insentif menyusul di tahap berikutnya.

---

## 5. Alur Status Tugas (MVP)

```
            ajukan                  approve
  [Draf] ───────────▶ [Diajukan] ───────────────▶ [Disetujui]  (final)
                          │   ▲
          tolak (+catatan)│   │ ajukan ulang (setelah revisi)
                          ▼   │
                       [Ditolak] ──┐
                          │        │ dibiarkan
                          └────────┘ (tetap Ditolak, final secara de facto)
```

- **Draf** — Katimker menyusun, bisa edit/hapus. Belum terlihat Kabid.
- **Diajukan** — terkunci untuk Katimker; menunggu penilaian Kabid.
- **Disetujui** — Kabid approve; `durasi_disetujui_menit` final (boleh < diajukan). Bila dikurangi, alasan wajib. **Status final.**
- **Ditolak** — Kabid tolak dengan catatan wajib. **Tidak terminal:** Katimker dapat merevisi isi tugas lalu **mengajukan ulang** (kembali ke Diajukan), atau membiarkannya tetap Ditolak.

Penilaian dilakukan **per-entri tugas** (bukan per-rekap). Setiap keputusan tercatat di audit.

> Keputusan Kabid di MVP: **approve** atau **tolak + catatan wajib**. Tidak ada status terpisah "Direvisi" — penolakan itu sendiri yang mengembalikan tugas ke Katimker untuk diperbaiki dan diajukan ulang. Riwayat setiap pengajuan/penolakan tersimpan di audit sehingga jejak revisi tetap dapat ditelusuri.

---

## 6. Pemetaan Approver (Katimker → Kabid)

- **Sepenuhnya manual.** Relasi unit→bidang→Kabid tidak ada di SIKEP (`ms_unit` flat), jadi pemetaan **tidak** diturunkan dari SIKEP sama sekali. Admin menetapkan tiap pasangan Katimker→Kabid lewat antarmuka.
- **Relasi one-to-one:** satu Katimker tepat satu Kabid. Mengubah pasangan = memperbarui baris yang sama (atau menonaktifkan lama + buat baru bila ingin menyimpan riwayat efektif).
- Antarmuka admin: daftar Katimker (dari SIKEP), pilih Kabid (dari SIKEP) untuk tiap Katimker; bisa edit/ubah.
- Katimker tanpa pemetaan tidak dapat mengajukan tugas (atau tugasnya tidak punya penilai) — perlu ditangani: tampilkan peringatan ke admin & blokir pengajuan sampai dipetakan.
- Perubahan pemetaan tercatat di audit (siapa, kapan, nilai lama→baru).

---

## 7. Aturan Jam & Bukti

- Input durasi dalam **jam + menit**, disimpan sebagai **menit (integer)**.
- `durasi_diajukan_menit > 0`.
- `0 ≤ durasi_disetujui_menit ≤ durasi_diajukan_menit`.
- Pengurangan jam atau penetapan 0 **wajib disertai alasan**.
- **Foto selfie wajib** minimal satu per tugas sebelum pengajuan. Dokumen & uraian teks opsional.
- Koordinat (lat/lng/akurasi) opsional; tidak menghalangi pengajuan bila izin lokasi ditolak.

---

## 8. Yang DILUAR Scope MVP

- Perhitungan/simulasi insentif (bobot 40/50/10, nominal, cap 100%).
- Alur approval berjenjang penuh (Kabak → Wadir → Direktur).
- Penguncian cut-off otomatis dan kebijakan pasca cut-off.
- Sinkronisasi absensi ke kalkulasi (absensi hanya ditampilkan sebagai konteks, bila diperlukan).
- Notifikasi, analitik kepatuhan.

---

## 9. Butir Verifikasi Saat Implementasi

1. Konsistensi penanda role: `ms_jabatan_lapangan_user` vs `ms_group_user` (mana yang paling andal untuk menentukan Katimker/Kabid).
2. Nilai `pengacak_pass_1` dan `pengacak_pass_2` dari `koneksi.php`/`fungsi.php` SIKEP untuk verifikasi password.
3. Konektivitas ke `10.20.1.116:2651` dari lingkungan aplikasi.
4. Bentuk pasti data absensi yang dibutuhkan bila nanti dipakai untuk rekap.

> Catatan: relasi unit→bidang→Kabid **tidak** diperlukan lagi — pemetaan approver sepenuhnya manual (lihat §6).

---

## 10. Keputusan Final (terkunci dari brainstorming)

1. **Pemetaan approver:** sepenuhnya manual oleh admin, one-to-one (satu Katimker satu Kabid). Tidak dari SIKEP.
2. **Status "Direvisi":** tidak ada status terpisah. Tugas **Ditolak bisa diajukan ulang** setelah direvisi, atau dibiarkan tetap Ditolak.
3. **Kardinalitas Katimker–Kabid:** one-to-one.

## 11. Pertanyaan Terbuka Tersisa (bisa diputuskan saat build)

1. Batas ukuran/jumlah lampiran dan kebijakan retensi file bukti.
2. Apakah peran **Admin** login via SIKEP juga (mis. ditandai di user lokal) atau akun lokal terpisah.
