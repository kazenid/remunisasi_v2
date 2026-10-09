# Requirements — Kinerja Katimker MVP (Remunisasi v2)

## Pendahuluan

Remunisasi v2 adalah ekstensi dari aplikasi kepegawaian **SIKEP** (PHP native, MySQL, DB `bangil_kepegawaian`). SIKEP tetap menjadi sumber kebenaran untuk pegawai, unit, jabatan, dan absensi. MVP ini membangun alur inti: **Katimker (Ketua Tim Kerja) mencatat tugas hariannya sendiri**, dan **Kabid (Kepala Bidang) menilai tiap tugas** (menyetujui atau menolak dengan catatan, serta menetapkan jam yang disetujui). Hasil akhirnya adalah rekap jam disetujui per jenis tugas sebagai dasar perhitungan insentif pada tahap berikutnya.

Landasan: `docs/PRD-aplikasi-kinerja-insentif.md`, `docs/rekapitulasi-kinerja-dan-insentif.md`, `docs/Perdir_RSUD_Bangil_186_2026.md`, dan `docs/MVP-keputusan-desain.md`.

### Istilah

- **SIKEP**: aplikasi kepegawaian sumber data, diakses read-only.
- **Katimker**: Ketua Tim Kerja; peran yang menginput tugas kinerja dirinya sendiri.
- **Kabid**: Kepala Bidang; peran yang menilai tugas Katimker binaannya.
- **Admin**: pengelola konfigurasi aplikasi dan pemetaan approver.
- **Tugas**: satu entri kegiatan kerja pada satu tanggal, berjenis **utama** atau **tambahan**.
- **Durasi**: lama pengerjaan tugas, diinput dalam jam+menit, disimpan sebagai menit (integer).

### Ruang Lingkup

Termasuk: autentikasi via SIKEP, input tugas oleh Katimker, bukti (foto selfie wajib), pengajuan, penilaian per-tugas oleh Kabid (approve/tolak + jam disetujui), siklus revisi setelah tolak, pemetaan approver manual oleh Admin, rekap jam disetujui, dan audit.

Di luar lingkup MVP: perhitungan/simulasi insentif (bobot 40/50/10), approval berjenjang penuh (Kabak→Wadir→Direktur), penguncian cut-off otomatis, sinkronisasi absensi ke kalkulasi, dan notifikasi.

---

## Requirements

### Requirement 1: Autentikasi menggunakan kredensial SIKEP

**User Story:** Sebagai pegawai (Katimker/Kabid/Admin), saya ingin login memakai kredensial SIKEP yang sudah saya miliki, sehingga saya tidak perlu akun baru.

#### Acceptance Criteria

1. WHEN pengguna mengirim username atau no_absen beserta password THEN sistem SHALL memverifikasi terhadap tabel `ms_user` SIKEP (read-only) menggunakan algoritma hash `md5(pengacak_1 . md5(password) . pengacak_2)`.
2. WHEN kredensial valid DAN `ms_user.aktif = 1` THEN sistem SHALL membuat sesi dan membuat/menyinkronkan user lokal (mirror ringan: `sikep_user_id`, `username`, `nama`, `no_absen`) tanpa menyimpan password.
3. IF `ms_user.aktif = 0` atau pengguna tidak ditemukan THEN sistem SHALL menolak login dengan pesan generik tanpa membedakan "user tidak ada" vs "password salah".
4. WHEN password dikirim THEN sistem SHALL NOT menerima master password hardcode SIKEP (`sdmjaya`, `kiduldalem`).
5. WHEN pengguna sudah login THEN sistem SHALL menentukan peran efektif (Katimker, Kabid, Admin) berdasarkan aturan Requirement 2.
6. WHERE koneksi ke SIKEP gagal atau timeout THEN sistem SHALL menampilkan pesan kegagalan integrasi dan SHALL NOT membuat sesi.
7. The system SHALL NOT melakukan operasi tulis apa pun ke database SIKEP pada proses autentikasi maupun proses lainnya.

### Requirement 2: Penentuan peran pengguna

**User Story:** Sebagai sistem, saya ingin menentukan peran tiap pengguna, sehingga hak akses dan menu yang ditampilkan sesuai.

#### Acceptance Criteria

1. WHEN pengguna login THEN sistem SHALL menandai pengguna sebagai **Katimker** jika terdaftar sebagai Kepala Tim di SIKEP (`ms_jabatan_lapangan_user.id_jabatan_lapangan = 3`, dengan penguat opsional `ms_group_user` group 7).
2. WHEN pengguna login THEN sistem SHALL menandai pengguna sebagai **Kabid** jika terdaftar pada jabatan Kabid di SIKEP (`ms_jabatan_lapangan_user.id_jabatan_lapangan IN (10,11,12)`).
3. WHEN pengguna ditetapkan sebagai Admin di konfigurasi lokal THEN sistem SHALL memberikan peran **Admin** terlepas dari jabatan SIKEP.
4. IF pengguna memiliki lebih dari satu peran THEN sistem SHALL mengizinkan peran-peran itu berlaku bersamaan dan menampilkan menu gabungan sesuai kewenangan.
5. IF pengguna tidak memiliki peran Katimker, Kabid, maupun Admin THEN sistem SHALL menolak akses ke fitur kinerja dengan pesan bahwa akun belum berperan.
6. WHEN sumber penanda role antara `ms_jabatan_lapangan_user` dan `ms_group_user` tidak konsisten THEN sistem SHALL menggunakan `ms_jabatan_lapangan_user` sebagai acuan utama.

### Requirement 3: Pemetaan approver (Katimker → Kabid) oleh Admin

**User Story:** Sebagai Admin, saya ingin menetapkan Kabid penilai untuk tiap Katimker, sehingga tugas Katimker dirutekan ke penilai yang benar.

#### Acceptance Criteria

1. WHEN Admin membuka halaman pemetaan THEN sistem SHALL menampilkan daftar Katimker (dari SIKEP) dan Kabid yang dipetakan untuk masing-masing (jika ada).
2. WHEN Admin menetapkan pasangan Katimker→Kabid THEN sistem SHALL menyimpan relasi **one-to-one** (satu Katimker tepat satu Kabid; `katimker_sikep_user_id` unik).
3. WHEN Admin mengubah Kabid untuk seorang Katimker THEN sistem SHALL memperbarui pemetaan dan mencatat perubahan (user, waktu, nilai lama→baru) ke audit.
4. The system SHALL NOT menurunkan pemetaan secara otomatis dari struktur SIKEP; seluruh pemetaan ditetapkan manual.
5. WHEN Admin memilih Kabid untuk suatu Katimker THEN sistem SHALL hanya mengizinkan pengguna yang berperan Kabid (Requirement 2.2) sebagai target.
6. IF seorang Katimker belum memiliki pemetaan THEN sistem SHALL menandai kondisi ini kepada Admin dan SHALL memblokir pengajuan tugas Katimker tersebut (lihat Requirement 5.6).

### Requirement 4: Katimker mencatat tugas harian (draf)

**User Story:** Sebagai Katimker, saya ingin mencatat tugas kerja harian saya dengan durasi dan bukti, sehingga kinerja saya terdokumentasi untuk dinilai.

#### Acceptance Criteria

1. WHEN Katimker membuat tugas THEN sistem SHALL menyimpan: tanggal kegiatan, jenis (`utama` | `tambahan`), judul, uraian (opsional), dan durasi diajukan.
2. WHEN Katimker memasukkan durasi THEN sistem SHALL menerima input dalam jam dan menit dan SHALL menyimpannya sebagai menit (integer) dengan nilai > 0.
3. WHEN Katimker menambahkan bukti THEN sistem SHALL mewajibkan minimal satu foto selfie dan SHALL mengizinkan dokumen serta uraian teks sebagai opsional.
4. WHERE perangkat menyediakan lokasi THEN sistem SHALL dapat menyimpan koordinat (lat, lng, akurasi) sebagai data opsional; IF izin lokasi ditolak THEN sistem SHALL tetap mengizinkan penyimpanan tugas.
5. WHEN tugas berstatus Draf THEN sistem SHALL mengizinkan Katimker mengubah atau menghapusnya.
6. WHEN tugas berstatus Draf THEN sistem SHALL NOT menampilkannya kepada Kabid.
7. WHEN sistem menyimpan tugas THEN sistem SHALL mencatat waktu pembuatan secara otomatis.

### Requirement 5: Katimker mengajukan tugas

**User Story:** Sebagai Katimker, saya ingin mengajukan tugas yang sudah lengkap, sehingga Kabid dapat menilainya.

#### Acceptance Criteria

1. WHEN Katimker mengajukan tugas THEN sistem SHALL memvalidasi bahwa durasi diajukan > 0 DAN minimal satu foto selfie terlampir.
2. IF validation gagal THEN sistem SHALL menolak pengajuan dan menampilkan alasan spesifik (durasi atau bukti kurang).
3. WHEN pengajuan valid THEN sistem SHALL mengubah status tugas menjadi **Diajukan** dan mencatat waktu pengajuan.
4. WHEN tugas berstatus Diajukan THEN sistem SHALL mengunci isi tugas dari perubahan oleh Katimker.
5. WHEN tugas diajukan THEN sistem SHALL merutekan tugas ke Kabid sesuai pemetaan approver (Requirement 3).
6. IF Katimker belum memiliki pemetaan approver THEN sistem SHALL memblokir pengajuan dan menampilkan pesan agar menghubungi Admin.

### Requirement 6: Kabid menilai tugas per-entri

**User Story:** Sebagai Kabid, saya ingin menilai tiap tugas Katimker binaan saya, sehingga jam yang disetujui mencerminkan kinerja aktual.

#### Acceptance Criteria

1. WHEN Kabid membuka daftar penilaian THEN sistem SHALL menampilkan tugas berstatus Diajukan dari Katimker yang dipetakan kepadanya saja.
2. WHEN Kabid membuka detail tugas THEN sistem SHALL menampilkan rincian tugas, durasi diajukan, bukti (foto/dokumen), koordinat bila ada, dan riwayat keputusan sebelumnya.
3. WHEN Kabid menyetujui tugas THEN sistem SHALL meminta durasi disetujui dengan batas `0 ≤ durasi_disetujui_menit ≤ durasi_diajukan_menit`.
4. IF durasi disetujui < durasi diajukan ATAU durasi disetujui = 0 THEN sistem SHALL mewajibkan alasan diisi.
5. WHEN Kabid menyetujui THEN sistem SHALL mengubah status menjadi **Disetujui**, menyimpan durasi disetujui dan catatan, serta mencatat waktu penilaian; status Disetujui SHALL bersifat final.
6. WHEN Kabid menolak THEN sistem SHALL mewajibkan catatan penolakan diisi dan mengubah status menjadi **Ditolak**.
7. The system SHALL NOT mengizinkan Kabid menilai tugas Katimker yang tidak dipetakan kepadanya.
8. WHEN keputusan dibuat THEN sistem SHALL mencatat keputusan (jenis, nilai, alasan/catatan, aktor, waktu) ke audit.

### Requirement 7: Siklus revisi setelah ditolak

**User Story:** Sebagai Katimker, saya ingin memperbaiki tugas yang ditolak dan mengajukannya ulang, sehingga kinerja yang valid tetap bisa dinilai.

#### Acceptance Criteria

1. WHEN tugas berstatus Ditolak THEN sistem SHALL mengizinkan Katimker mengedit isi tugas (durasi, bukti, uraian).
2. WHEN Katimker mengajukan ulang tugas yang Ditolak THEN sistem SHALL memvalidasi ulang (Requirement 5.1) dan mengubah status menjadi **Diajukan**.
3. WHEN tugas diajukan ulang THEN sistem SHALL mempertahankan riwayat penolakan sebelumnya (catatan, aktor, waktu) di audit.
4. WHILE tugas berstatus Ditolak dan tidak diajukan ulang THEN sistem SHALL membiarkannya tetap Ditolak tanpa menghitungnya pada rekap jam disetujui.
5. The system SHALL NOT mengizinkan perubahan pada tugas berstatus Disetujui.

### Requirement 8: Rekap jam per periode

**User Story:** Sebagai Katimker (dan Kabid/Admin), saya ingin melihat rekap jam yang disetujui, sehingga capaian kinerja terlihat jelas sebelum dasar insentif dihitung.

#### Acceptance Criteria

1. WHEN pengguna membuka rekap untuk suatu periode (bulan, tahun) THEN sistem SHALL menampilkan total durasi disetujui untuk tugas **utama** dan total untuk tugas **tambahan** secara terpisah.
2. WHEN menghitung rekap THEN sistem SHALL hanya menyertakan tugas berstatus **Disetujui**.
3. WHEN menampilkan rekap THEN sistem SHALL menampilkan durasi dalam format jam dan menit yang mudah dibaca, dengan sumber penyimpanan tetap menit (integer).
4. WHEN Katimker membuka rekap THEN sistem SHALL menampilkan hanya data miliknya; WHEN Kabid membuka rekap THEN sistem SHALL menampilkan data Katimker binaannya; WHEN Admin membuka rekap THEN sistem SHALL menampilkan lintas pengguna.
5. The system SHALL NOT menghitung nilai insentif pada MVP ini (hanya rekap jam).

### Requirement 9: Otorisasi dan isolasi data

**User Story:** Sebagai pemilik sistem, saya ingin tiap peran hanya mengakses data yang menjadi haknya, sehingga kerahasiaan dan integritas terjaga.

#### Acceptance Criteria

1. The system SHALL membatasi Katimker hanya pada tugas dan rekap miliknya sendiri.
2. The system SHALL membatasi Kabid hanya pada tugas dan rekap Katimker yang dipetakan kepadanya.
3. The system SHALL membatasi fitur pemetaan approver dan konfigurasi hanya untuk Admin.
4. WHEN pengguna mencoba mengakses data di luar haknya THEN sistem SHALL menolak dengan respons tidak berwenang.
5. The system SHALL menyimpan lampiran bukti dengan kontrol akses sehingga hanya pemilik, Kabid penilai, dan Admin yang dapat melihatnya.

### Requirement 10: Audit dan ketertelusuran

**User Story:** Sebagai Admin/auditor, saya ingin menelusuri perubahan penting, sehingga keputusan dapat dipertanggungjawabkan.

#### Acceptance Criteria

1. WHEN status tugas berubah (ajukan, approve, tolak, ajukan ulang) THEN sistem SHALL mencatat peristiwa dengan aktor, waktu, status lama→baru, dan nilai terkait (durasi disetujui, alasan).
2. WHEN pemetaan approver berubah THEN sistem SHALL mencatat perubahan ke audit.
3. The system SHALL menyimpan audit secara append-only (tidak diubah/dihapus lewat alur normal aplikasi).
4. WHEN Admin membuka audit THEN sistem SHALL menampilkan riwayat yang dapat difilter per pengguna dan periode.

### Requirement 11: Isolasi integrasi SIKEP (non-fungsional)

**User Story:** Sebagai pengelola data kepegawaian, saya ingin memastikan aplikasi baru tidak pernah mengubah data SIKEP, sehingga sumber data tetap aman.

#### Acceptance Criteria

1. The system SHALL mengakses SIKEP hanya melalui koneksi database `sikep` yang dikonfigurasi read-only.
2. The system SHALL NOT menjalankan migration, seeder, atau operasi tulis apa pun terhadap koneksi `sikep`.
3. WHEN data SIKEP dirujuk oleh entitas lokal THEN sistem SHALL menyimpan `sikep_user_id` sebagai referensi dan SHALL NOT menyalin data pegawai yang tidak diperlukan.
4. IF koneksi SIKEP tidak tersedia THEN sistem SHALL menampilkan status kegagalan integrasi pada operasi yang bergantung padanya tanpa merusak data lokal.
