# PRD: Aplikasi Perekaman Kinerja dan Perhitungan Insentif

**Status:** Draf untuk diskusi  
**Versi:** 0.1  
**Tanggal:** 2 Oktober 2026

## 1. Ringkasan

Aplikasi ini merupakan ekstensi dari sistem kepegawaian yang sudah digunakan. Sistem kepegawaian tetap menjadi sumber data pegawai, unit, struktur organisasi, dan absensi. Aplikasi baru berfokus pada pencatatan kegiatan kerja harian, pengajuan jam pengerjaan, verifikasi bukti, persetujuan berjenjang, dan penyediaan data untuk perhitungan insentif.

Pegawai mengisi jadwal/kegiatan kerja setiap hari, membedakan tugas utama dan tugas tambahan. Setiap tugas memiliki rentang waktu atau jumlah jam pengerjaan dan bukti pendukung. Bukti dapat menyertakan koordinat lokasi. Atasan meninjau laporan dan menetapkan jumlah jam yang disetujui; nilai tersebut boleh sama dengan atau lebih kecil dari jam yang diajukan.

## 2. Latar Belakang dan Masalah

Data dasar kepegawaian dan absensi telah tersedia, sehingga aplikasi tidak perlu membangun ulang pengelolaan pegawai. Yang belum tersedia adalah mekanisme terstruktur untuk merekam pekerjaan aktual harian, membedakan tugas utama dan tambahan, mengukur jam yang diajukan dibandingkan jam yang disetujui, dan menghubungkan hasilnya dengan insentif.

## 3. Tujuan

- Memudahkan pegawai mencatat pekerjaan harian dan jam yang digunakan.
- Menyediakan bukti yang dapat diverifikasi, termasuk waktu unggah dan koordinat bila relevan.
- Membuat proses koreksi dan persetujuan dapat ditelusuri.
- Menghitung capaian jam tugas utama dan tugas tambahan dari jam yang disetujui.
- Menyediakan rekap kinerja sebagai masukan perhitungan insentif.

## 4. Bukan Tujuan

- Menggantikan sistem kepegawaian sebagai pengelola master pegawai, unit, jabatan, struktur, atau absensi.
- Menggantikan aplikasi absensi atau menjadi alat pelacakan lokasi pegawai terus-menerus.
- Menghitung atau mengubah payroll di luar komponen insentif yang ditentukan.

## 5. Pengguna dan Hak Akses

| Peran | Kemampuan utama |
|---|---|
| Pegawai | Melihat profil dan absensi terhubung; membuat dan mengubah laporan harian sebelum diajukan; mengirim laporan; menanggapi revisi; melihat status, jam disetujui, dan rekap pribadi. |
| Katker (Ketua Tim/Kerja) | Memeriksa laporan bawahan, mengembalikan untuk revisi, menolak dengan alasan, atau menyetujui jam; meneruskan ke tingkat berikutnya. |
| Kabak (Kepala Bagian) | Melakukan pemeriksaan dan persetujuan pada tahap berikutnya sesuai struktur. |
| Wadir (Wakil Direktur) | Melakukan pemeriksaan dan persetujuan pada tahap berikutnya sesuai struktur. |
| Direktur | Memberikan persetujuan akhir sesuai alur yang berlaku. |
| Administrator | Mengelola konfigurasi periode, target jam, kategori kegiatan, pemetaan approver, bobot, dan akses; melihat audit dan rekap lintas unit. |

Hak persetujuan ditentukan dari struktur organisasi di sistem kepegawaian. Aplikasi perlu menangani perubahan struktur atau pejabat approver dengan aturan efektif yang dapat dikonfigurasi.

## 6. Ruang Lingkup Fungsional

### 6.1 Integrasi data kepegawaian

Aplikasi mengambil atau menyinkronkan:

- Identitas pegawai dan status kepegawaian.
- Unit, jabatan, atasan, dan rantai persetujuan.
- Data absensi harian yang diperlukan untuk rekap.

Setiap data yang disinkronkan menyimpan ID sumber dan waktu sinkronisasi. Kegagalan integrasi harus ditampilkan kepada administrator dan tidak boleh menyebabkan laporan tersimpan dengan pegawai/unit yang salah.

### 6.2 Kalender dan pengisian harian

- Pegawai membuka kalender periode dan memilih tanggal kerja.
- Aplikasi menampilkan ringkasan absensi dari sistem kepegawaian jika tersedia.
- Pegawai membuat satu atau lebih entri tugas per hari.
- Entri dapat disimpan sebagai draf dan diajukan harian atau dalam mekanisme pengajuan periode yang ditetapkan.
- Pegawai dapat melihat kelengkapan hari dan status pengajuan.

### 6.3 Data setiap tugas

Setiap entri tugas memuat:

- Tanggal kegiatan.
- Jenis tugas: **Tugas Utama** atau **Tugas Tambahan**.
- Judul dan uraian hasil/aktivitas.
- Kategori atau tupoksi terkait, bila tersedia.
- Waktu mulai dan selesai (opsional bila jumlah jam menjadi sumber utama; keputusan perlu ditetapkan) serta **jam yang diajukan**.
- Lampiran bukti: foto, dokumen/laporan, atau tangkapan layar.
- Koordinat latitude/longitude dan akurasi lokasi, bila diberikan/tersedia.
- Waktu pencatatan dan pengajuan yang dibuat otomatis oleh sistem.

Koordinat dicatat sebagai bukti kontekstual saat tugas dilaporkan, bukan sebagai pelacakan berkala. Untuk bukti jarak jauh, koordinat dapat tidak tersedia; lampiran digital tetap dapat digunakan.

### 6.4 Pengajuan dan koreksi

- Pegawai mengajukan entri atau kumpulan entri sesuai periode pengajuan.
- Setelah diajukan, isi laporan terkunci untuk pegawai kecuali dikembalikan untuk revisi atau dibuka oleh pihak berwenang.
- Reviewer dapat meminta revisi dengan alasan yang wajib diisi.
- Pegawai mengubah laporan dan mengajukan ulang; riwayat versi dan komentar tetap tersimpan.
- Penolakan memerlukan alasan dan mengakhiri pengajuan tersebut sesuai kebijakan periode.

### 6.5 Pemeriksaan dan persetujuan jam

Pada setiap tahap persetujuan:

- Atasan melihat rincian tugas, bukti, koordinat (jika ada), jam yang diajukan, dan riwayat.
- Atasan menetapkan **jam disetujui** dari 0 sampai jam yang diajukan. Jam dapat disetujui penuh atau sebagian.
- Jika jam dikurangi atau menjadi 0, alasan wajib dicatat.
- Atasan dapat mengembalikan untuk revisi atau menolak sesuai kewenangannya.
- Jam dan keputusan tiap tahap tersimpan. Untuk perhitungan, digunakan jumlah jam yang berlaku setelah persetujuan akhir; aturan akumulasi keputusan antarjenjang harus dikonfigurasi dan ditetapkan sebelum implementasi.

Alur standar: **Pegawai → Katker → Kabak → Wadir → Direktur**. Aplikasi menampilkan tahap aktif dan approver yang bertanggung jawab.

### 6.6 Rekap dan insentif

Rekap per pegawai dan periode sekurang-kurangnya memuat:

- Kehadiran yang bersumber dari sistem kepegawaian.
- Total jam tugas utama yang disetujui.
- Total jam tugas tambahan yang disetujui.
- Target jam tugas utama periode tersebut.
- Persentase capaian setiap komponen dengan batas maksimum 100%.
- Nilai simulasi insentif dan rincian komponennya.

Formula awal mengikuti notula:

```text
capaian_kehadiran = min(persentase_kehadiran, 100%)
capaian_tugas_utama = min(jam_utama_disetujui / target_jam_utama, 100%)
capaian_tugas_tambahan = min(jam_tambahan_disetujui / target_jam_tambahan, 100%)

persentase_insentif =
  40% × capaian_kehadiran
  + 50% × capaian_tugas_utama
  + 10% × capaian_tugas_tambahan

insentif = nominal_insentif_dasar × persentase_insentif
```

Contoh: jika kehadiran dan tugas utama mencapai 100%, sedangkan tugas tambahan 0%, maka komponen yang tercapai adalah 90% dari nominal dasar. Jam tugas tambahan yang disetujui tetap dapat dicatat setelah capaian mencapai 100%, tetapi tidak menaikkan persentase insentif di atas batas tersebut.

Formula ini mengasumsikan target jam tugas tambahan dapat didefinisikan. Besaran target, dasar persentase kehadiran, nominal insentif, pembulatan, dan perlakuan hari libur/cuti masih perlu disepakati. Sebelum disepakati, rekap sebaiknya diberi label simulasi dan tidak menjadi dasar pembayaran final.

## 7. Status Laporan

Status tingkat pengajuan:

1. **Draf** — belum diajukan.
2. **Diajukan / Proses** — menunggu pemeriksaan pada tahap aktif.
3. **Direvisi** — dikembalikan kepada pegawai untuk perbaikan.
4. **Ditolak** — pengajuan berakhir dengan alasan.
5. **Disetujui** — seluruh tahap wajib selesai.

Status setiap tugas juga dapat ditampilkan bila keputusan dibuat per tugas. Sistem harus membedakan status pengajuan periode dan status item jika keduanya digunakan.

## 8. Aturan Bisnis Awal

- Jam disetujui tidak boleh negatif dan tidak boleh melebihi jam yang diajukan.
- Jam diajukan harus lebih besar dari nol; batas durasi per tugas dan total per hari perlu ditetapkan.
- Tugas utama adalah pekerjaan sesuai tupoksi pada jam kerja efektif. Tugas di luar tupoksi atau di luar jam kerja efektif dapat diklasifikasikan sebagai tugas tambahan.
- Reviewer berwenang mengubah klasifikasi utama/tambahan dengan alasan dan jejak audit.
- Bukti wajib untuk tugas tambahan; bukti untuk tugas utama dapat diwajibkan berdasarkan kebijakan unit atau kategori.
- Koordinat tidak wajib untuk tugas jarak jauh atau ketika perangkat tidak memberikan izin lokasi.
- Pencapaian jam dan insentif dihitung dari jam yang telah disetujui final, bukan sekadar jam yang diajukan.
- Cut-off pengajuan/revisi tanggal 10 dan target realisasi tanggal 15 mengikuti notula, dengan kalender dan zona waktu operasional yang dapat dikonfigurasi.
- Data yang melewati cut-off mengikuti kebijakan yang harus ditentukan (misalnya dikunci atau perlu pembukaan khusus dengan audit).

## 9. Alur Utama

1. Aplikasi menyinkronkan identitas pegawai, struktur, dan absensi.
2. Pegawai memilih tanggal, membuat tugas, memasukkan jam, bukti, dan koordinat bila relevan.
3. Pegawai mengajukan laporan.
4. Katker memeriksa; dapat menyetujui jam penuh/sebagian, meminta revisi, atau menolak.
5. Laporan yang lolos bergerak melalui Kabak, Wadir, dan Direktur sesuai alur.
6. Setelah persetujuan akhir, jam masuk ke rekap periode dan perhitungan simulasi insentif.
7. Pegawai dan administrator melihat rekap serta riwayat keputusan.

## 10. Kebutuhan Nonfungsional

- Hak akses berbasis peran dan relasi atasan-bawahan.
- Jejak audit untuk perubahan data, klasifikasi, jam yang disetujui, keputusan, dan konfigurasi.
- Lampiran disimpan dengan kontrol akses; koordinat dan bukti hanya terlihat bagi pihak yang berwenang.
- Komunikasi integrasi menggunakan autentikasi dan pencatatan kegagalan sinkronisasi.
- Antarmuka responsif untuk desktop dan ponsel.
- Waktu disimpan konsisten dan ditampilkan sesuai zona waktu instansi.
- Sistem menyediakan ekspor rekap yang dapat ditelusuri ke laporan sumber.

## 11. Indikator Keberhasilan

- Persentase laporan harian yang diajukan sebelum cut-off.
- Persentase laporan yang lengkap bukti dan tidak perlu dikembalikan.
- Waktu median dari pengajuan sampai persetujuan akhir.
- Persentase keputusan yang memiliki alasan saat jam dikurangi atau laporan direvisi/ditolak.
- Kesesuaian rekap jam dengan jumlah jam yang disetujui pada laporan sumber.
- Jumlah koreksi manual pada rekap insentif.

## 12. Risiko dan Ketergantungan

- Kualitas dan konsistensi API/data dari sistem kepegawaian, khususnya struktur atasan dan absensi.
- Perubahan unit atau approver di tengah periode dapat mengubah rute persetujuan.
- Koordinat dapat tidak akurat atau tidak tersedia; penilaian bukti tidak boleh hanya bergantung pada koordinat.
- Perlu kebijakan jelas untuk tugas lintas hari, lembur, cuti, dinas luar, hari libur, dan laporan terlambat.
- Perlu keputusan apakah jam disetujui pada setiap tingkat dapat dikurangi lagi atau hanya keputusan akhir yang berlaku.

## 13. Pertanyaan Terbuka untuk Diskusi

1. Apakah satuan input jam berupa jam desimal, jam-menit, atau rentang waktu mulai-selesai? Apakah keduanya diperlukan?
2. Target tugas utama 150 jam per bulan berlaku untuk semua pegawai, atau mengikuti jadwal kerja/unit dan prorata hari kerja?
3. Bagaimana target komponen tugas tambahan ditentukan agar bobot 10% dapat dihitung konsisten?
4. Apakah approver di setiap tingkat mengisi nilai jam disetujui, atau hanya satu tahap (misalnya Katker dan persetujuan akhir)? Jika berbeda, nilai tahap mana yang menjadi acuan?
5. Apakah pengajuan dilakukan setiap hari, mingguan, atau satu kali untuk seluruh periode bulanan?
6. Apakah tugas utama wajib memiliki bukti? Apakah tugas tambahan harus selalu menyertakan foto/laporan/screenshot?
7. Apakah koordinat diambil otomatis saat laporan dibuat, atau pegawai dapat memilih lampiran dengan lokasi? Bagaimana jika izin lokasi ditolak?
8. Apakah keputusan akhir per tugas atau per pengajuan harian/periode?
9. Apa kebijakan setelah cut-off tanggal 10, termasuk pegawai yang sedang cuti atau dinas?
10. Siapa yang mengelola nominal insentif dasar, bobot, target, dan periode efektifnya?

## 14. Rekomendasi Tahapan Produk

### Tahap 1 — Pencatatan dan persetujuan

Integrasi master dasar, kalender, input tugas, jam diajukan, bukti dan koordinat, revisi, alur berjenjang, jam disetujui, audit, dan rekap jam.

### Tahap 2 — Simulasi insentif

Konfigurasi target dan bobot, integrasi absensi ke kalkulasi, rincian per komponen, ekspor, dan rekonsiliasi.

### Tahap 3 — Penyempurnaan operasional

Notifikasi, analitik kepatuhan, pengelolaan pengecualian/cut-off, serta optimasi alur berdasarkan evaluasi penggunaan.
