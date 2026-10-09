# Requirements — Kinerja Tahap 2 (Fitur Lanjutan)

## Pendahuluan

Iterasi ini memperluas MVP (`kinerja-katimker-mvp`) dengan empat fitur yang disepakati dalam brainstorming, **tanpa** membangun mesin perhitungan/pembayaran insentif final (itu menunggu parameter kebijakan RSUD: cap, pembulatan, nominal). Fokus di sini: komponen kehadiran yang akurat dari SIKEP, keleluasaan Kabid atas klasifikasi & penugasan tambahan, dan target yang dapat dikonfigurasi global + per individu.

Landasan tambahan: analisis `SIKEP/lihat/setting_absensi_rekap.php` dan tabel `ms_pengurangan_insentif`.

### Istilah tambahan
- **Pengurangan insentif (SIKEP)**: persentase potongan harian akibat terlambat, pulang awal, tidak scan, tanpa keterangan, atau cuti — diakumulasi per periode. Sumber kebenaran kehadiran.
- **Persen kehadiran**: `100 − total_pengurangan%` (dibatasi minimum 0). Dipakai sebagai komponen kehadiran.
- **Penugasan tambahan**: tugas berjenis tambahan yang dibuat oleh Kabid untuk Katimker; Katimker melengkapi bukti.
- **Target efektif**: target jam untuk seorang Katimker pada periode = override individu bila ada, selain itu target global.

---

## Requirements

### Requirement 1: Komponen kehadiran dari penilaian absensi SIKEP

**User Story:** Sebagai sistem, saya ingin menghitung persentase kehadiran seorang pegawai pada satu periode mengikuti penilaian absensi SIKEP, sehingga komponen kehadiran akurat dan konsisten dengan SIKEP.

#### Acceptance Criteria

1. WHEN menghitung kehadiran untuk (sikep_user_id, tahun, bulan) THEN sistem SHALL mereplikasi logika `setting_absensi_rekap.php`: menilai tiap tanggal atas cuti, jadwal libur, terlambat, pulang awal, tidak scan, dan tanpa keterangan.
2. WHEN sebuah tanggal berjadwal libur (`ms_jam_kerja.libur = 1`) THEN sistem SHALL NOT menghitung pengurangan untuk tanggal itu (hari libur mengikuti absensi SIKEP).
3. WHEN pegawai cuti pada suatu tanggal (`cuti.approved = 1`) THEN sistem SHALL menerapkan pengurangan sesuai kategori (`ms_pengurangan_insentif_kategori`: Sakit, DL, atau Cuti umum).
4. WHEN terlambat atau pulang awal THEN sistem SHALL mengambil persentase berjenjang dari `ms_pengurangan_insentif` sesuai rentang menit dan tanggal berlaku.
5. WHEN tidak scan masuk/keluar (salah satu) THEN sistem SHALL menerapkan kategori "Tidak Scan"; WHEN keduanya tidak scan THEN sistem SHALL menerapkan kategori "TK" (tanpa keterangan).
6. WHEN seluruh tanggal dinilai THEN sistem SHALL menghitung `persen_kehadiran = max(0, 100 − total_pengurangan)` dan menyediakan rincian (total pengurangan, jumlah hadir/terlambat/cuti/libur/tanpa-keterangan).
7. The system SHALL mengakses seluruh data absensi SIKEP secara read-only.
8. IF data absensi periode belum tersedia di SIKEP THEN sistem SHALL mengembalikan status "tidak tersedia" tanpa error.
9. WHEN hasil dihitung THEN sistem SHALL mendekati nilai "Pengurangan Insentif" pada laporan `setting_absensi_rekap.php` untuk pegawai & periode yang sama (diverifikasi saat implementasi).

### Requirement 2: Kabid memindah klasifikasi tugas (utama ↔ tambahan)

**User Story:** Sebagai Kabid, saya ingin memindahkan klasifikasi tugas antara utama dan tambahan, sehingga penggolongan sesuai kriteria tupoksi.

#### Acceptance Criteria

1. WHEN Kabid menilai tugas berstatus diajukan THEN sistem SHALL mengizinkan menetapkan jenis (`utama`/`tambahan`) saat menyetujui.
2. WHEN Kabid melakukan aksi terpisah pada tugas berstatus diajukan THEN sistem SHALL mengizinkan mengubah jenis tanpa harus menyetujui/menolak.
3. WHEN Kabid mengubah jenis THEN sistem SHALL mewajibkan alasan dan mencatat perubahan (jenis lama→baru, aktor, waktu) ke audit.
4. The system SHALL hanya mengizinkan Kabid yang dipetakan ke pemilik tugas (snapshot) untuk mengubah jenis.
5. The system SHALL NOT mengizinkan perubahan jenis pada tugas berstatus disetujui atau ditolak.
6. WHEN jenis berubah THEN rekap periode SHALL mengelompokkan tugas pada jenis terbaru.

### Requirement 3: Kabid membuat penugasan tambahan untuk Katimker

**User Story:** Sebagai Kabid, saya ingin memberi penugasan tambahan kepada Katimker binaan, sehingga pekerjaan di luar tupoksi tercatat dan dapat dinilai.

#### Acceptance Criteria

1. WHEN Kabid membuat penugasan tambahan untuk seorang Katimker THEN sistem SHALL membuat tugas berjenis **tambahan** yang dimiliki Katimker tersebut, dengan penanda dibuat oleh Kabid.
2. WHEN penugasan dibuat THEN jenisnya SHALL terkunci sebagai tambahan dan SHALL hanya dapat diubah oleh Kabid atau Admin (bukan Katimker).
3. WHEN penugasan dibuat THEN statusnya SHALL menunggu Katimker melengkapi bukti dan durasi (status menunggu bukti).
4. WHEN Katimker membuka penugasan tersebut THEN sistem SHALL mengizinkan mengisi/mengubah bukti, uraian, dan durasi, tetapi SHALL NOT mengizinkan mengubah jenis.
5. WHEN Katimker melengkapi (foto selfie wajib + durasi > 0) dan mengajukan THEN sistem SHALL mengalirkannya ke penilaian Kabid seperti tugas biasa.
6. The system SHALL hanya mengizinkan Kabid yang dipetakan ke Katimker tersebut (atau Admin) membuat penugasan untuknya.
7. WHEN penugasan tambahan tercatat THEN peristiwa SHALL masuk audit (aktor Kabid, Katimker target).

### Requirement 4: Target global dan override per individu

**User Story:** Sebagai Admin (atau atasan), saya ingin menetapkan target jam tugas utama dan tambahan secara global maupun per individu, sehingga capaian dihitung terhadap target yang relevan.

#### Acceptance Criteria

1. WHEN Admin menetapkan target global THEN sistem SHALL menyimpan target jam/bulan untuk tugas utama dan tambahan yang berlaku bagi semua Katimker tanpa override.
2. WHEN Admin atau Kabid pembina menetapkan target untuk seorang Katimker THEN sistem SHALL menyimpan override individu (target utama & tambahan) untuk pengguna tersebut.
3. WHEN menentukan target efektif seorang Katimker pada suatu periode THEN sistem SHALL memakai override individu bila ada, selain itu target global.
4. The system SHALL menyatakan target dalam jam per bulan, disimpan sebagai menit (konsisten dengan `task_reports`).
5. The system SHALL membatasi penetapan target global hanya untuk Admin, dan override individu untuk Admin atau Kabid yang dipetakan ke Katimker tersebut.
6. WHEN target berubah THEN perubahan SHALL dicatat ke audit.
7. The system SHALL NOT menghitung nilai insentif pada iterasi ini; target hanya ditampilkan dan disiapkan untuk kalkulasi tahap berikutnya.

### Requirement 5: Konsistensi & non-regresi

**User Story:** Sebagai pemilik sistem, saya ingin fitur baru tidak merusak alur MVP, sehingga aplikasi tetap stabil.

#### Acceptance Criteria

1. The system SHALL mempertahankan seluruh perilaku MVP (pencatatan, pengajuan, penilaian, revisi, rekap, audit, akses bukti).
2. WHEN fitur baru ditambahkan THEN seluruh test yang ada SHALL tetap lulus.
3. The system SHALL menampilkan kehadiran, target, dan rekap dengan label yang jelas bahwa nilai insentif belum dihitung (simulasi/persiapan).
