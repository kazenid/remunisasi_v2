# Implementation Plan — Kinerja Katimker MVP (Remunisasi v2)

Rencana ini memecah implementasi menjadi langkah inkremental yang dapat diuji. Dikerjakan berurutan; tiap langkah membangun di atas langkah sebelumnya. Verifikasikan tiap langkah (build/test) sebelum lanjut. Rujukan `_Requirements:_` mengacu ke `requirements.md`.

- [x] 1. Fondasi integrasi: dua koneksi database
  - Tambahkan koneksi `sikep` di `config/database.php` membaca `SIKEP_DB_*` dari `.env`; tambahkan entri `.env` dan `.env.example` (host, port, db, user, pass, salt).
  - Pastikan perintah `migrate` hanya menargetkan koneksi default `mysql`.
  - Verifikasi koneksi `sikep` dapat membaca 1 baris `ms_user` (uji konektivitas) dan encoding latin1 terbaca benar.
  - _Requirements: 1.1, 11.1, 11.2_
  - ✓ Dev memakai salinan lokal `bangil_kepegawaian` (1664 ms_user terbaca; jabatan 3/10/11/12 benar).

- [x] 2. Model read-only SIKEP
  - [x] 2.1 Buat `SikepModel` abstract (connection `sikep`, timestamps off, override `save/delete/insert/update` → throw).
    - _Requirements: 11.1, 11.2_
  - [x] 2.2 Buat `SikepUser` (`ms_user`): `findByLogin`, accessor `nama_lengkap`, `isKatimker()`, `isKabid()`.
    - _Requirements: 1.1, 2.1, 2.2_
  - [x] 2.3 Buat `SikepJabatanLapanganUser`, `SikepGroupUser`, `SikepUnit`, `SikepUnitUser`.
    - _Requirements: 2.1, 2.2, 2.6_
  - [x] 2.4 Tulis unit test yang memastikan operasi tulis pada model SIKEP melempar exception.
    - _Requirements: 11.2_

- [x] 3. Autentikasi SIKEP
  - [x] 3.1 Buat `SikepAuthService` dengan `hash()` (salt dari config) dan `attempt()`; tolak master password hardcode; hanya `aktif=1`.
    - _Requirements: 1.1, 1.3, 1.4, 1.7_
  - [x] 3.2 **Formula hash tervalidasi terhadap hash nyata**: `md5("cba654" . md5(password))` (SALT_1=cba654, SALT_2 kosong) — bukan asumsi awal abc123/cba654. Unit test vektor-uji ada.
    - _Requirements: 1.1_
  - [x] 3.3 Buat `RoleResolver::resolve()` (acuan utama `ms_jabatan_lapangan_user`: 3=Katimker, 10/11/12=Kabid; Admin dari flag lokal).
    - _Requirements: 2.1, 2.2, 2.3, 2.6_
  - [x] 3.4 LoginController + halaman `Auth/Login`; buat/sinkron user lokal saat sukses (tanpa simpan password); pesan generik saat gagal; tangani kegagalan koneksi SIKEP (tidak buat sesi).
    - _Requirements: 1.2, 1.3, 1.5, 1.6, 2.4, 2.5_
  - [x] 3.5 Feature test: login sukses, gagal (pesan sama), akun nonaktif ditolak, master password ditolak.
    - _Requirements: 1.1, 1.3, 1.4_

- [x] 4. Skema data lokal (migrations & model)
  - [x] 4.1 Migration `users` (sikep_user_id unik, username, nama, no_absen, is_admin, last_sync_at) + model `User`.
    - _Requirements: 1.2, 2.3, 11.3_
  - [x] 4.2 Migration `periods` (tahun, bulan unik, cut_off, target_cair, status) + model `Period`.
    - _Requirements: 8.1_
  - [x] 4.3 Migration `approver_mappings` (katimker_sikep_user_id unik, kabid_sikep_user_id, aktif, berlaku_mulai, diubah_oleh) + model `ApproverMapping`.
    - _Requirements: 3.2, 3.4_
  - [x] 4.4 Migration `task_reports` (seluruh kolom + enum status/jenis + index) + model `TaskReport`.
    - _Requirements: 4.1, 4.2, 5.3, 6.3, 6.5_
  - [x] 4.5 Migration `task_attachments` (tipe, path, nama_asli, mime, ukuran, diambil_pada) + model `TaskAttachment`.
    - _Requirements: 4.3_
  - [x] 4.6 Migration `audit_logs` (append-only, meta json) + model `AuditLog` + `AuditLogger::record()`.
    - _Requirements: 10.1, 10.2, 10.3_

- [x] 5. Pemetaan approver (Admin)
  - [x] 5.0 Fondasi peran: `HandleInertiaRequests` share `auth.user`+roles; middleware `EnsureRole` (alias `role`); `User::roles()/hasRole()`.
    - _Requirements: 2.4, 9.3_
  - [x] 5.1 `ApproverMappingService`: `listKatimker()`, `listKabid()`, `assign()` (validasi target harus Kabid, one-to-one upsert), `unassigned()`.
    - _Requirements: 3.1, 3.2, 3.5, 3.6_
  - [x] 5.2 `ApproverMappingPolicy` (Admin-only) + middleware peran admin + halaman `Admin/ApproverMapping` (Sakai DataTable+Select+Toast) + menu sidebar admin.
    - _Requirements: 3.1, 3.3, 9.3_
  - [x] 5.3 Catat perubahan pemetaan ke audit (`mapping.update`).
    - _Requirements: 3.3, 10.2_
  - [x] 5.4 Feature test: assign/ubah mapping, tolak target non-Kabid, hanya Admin yang boleh (5 test pass).
    - _Requirements: 3.2, 3.5, 9.3_

- [x] 6. Katimker — kelola tugas (draf)
  - [x] 6.1 `TaskService::createDraft/updateDraft/deleteDraft`; input durasi jam+menit → simpan menit (>0); period via `Period::forDate`.
    - _Requirements: 4.1, 4.2, 4.5, 4.7_
  - [x] 6.2 Unggah bukti ke disk privat (`storage/app/private/bukti/...`); validasi tipe/ukuran (foto jpeg/png 5MB, dokumen pdf 10MB); koordinat opsional; hapus lampiran tersimpan.
    - _Requirements: 4.3, 4.4_
  - [x] 6.3 `TaskReportPolicy` (pemilik + status editable) + `TaskController` + route `role:katimker` + halaman `Katimker/TaskForm` & `Katimker/TaskList` (komponen `DurationInput`, `SelfieCapture`) + menu sidebar.
    - _Requirements: 4.5, 4.6, 9.1_
  - [x] 6.4 Feature test: buat draf (durasi→menit), durasi 0 ditolak, dokumen non-PDF ditolak, isolasi pemilik 403, non-draf tak bisa dihapus, hapus draf menghapus lampiran (6 test pass).
    - _Requirements: 4.2, 4.6_
  - [x] 6.5 Command `app:set-admin` untuk menandai Admin lokal (uji manual peran).

- [x] 7. Katimker — pengajuan tugas
  - [x] 7.1 `TaskService::submit`: validasi durasi>0 + ada foto selfie + ada mapping; set status `diajukan`, snapshot `kabid_sikep_user_id`, catat `diajukan_pada`; bersihkan jejak penilaian lama; audit `task.submit`. (`TaskSubmissionException` untuk pesan jelas.)
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 10.1_
  - [x] 7.2 `TaskController::submit` + route `tugas.submit`; blokir submit bila belum dipetakan (flash error "hubungi administrator"); edit terkunci via Policy `isEditable`.
    - _Requirements: 3.6, 5.6_
  - [x] 7.3 Tombol "Ajukan" di `TaskList` (status draf/ditolak) + konfirmasi + catatan penolakan tampil; flash error → toast.
    - _Requirements: 5.1, 5.5_
  - [x] 7.4 Feature test: submit valid (snapshot kabid + audit); tanpa selfie ditolak; tanpa mapping diblokir; status diajukan tak bisa diajukan lagi; resubmit dari ditolak (5 test pass).
    - _Requirements: 5.1, 5.2, 5.4, 5.6_

- [x] 8. Kabid — penilaian per-tugas
  - [x] 8.1 `ReviewService::listForKabid` (status diajukan via scope `untukKabid`) + halaman `Kabid/ReviewList` (nama Katimker diresolusi dari SIKEP).
    - _Requirements: 6.1, 9.2_
  - [x] 8.2 `Kabid/ReviewDetail`: rincian tugas, bukti (link `bukti.show`), koordinat, uraian; tombol kembali.
    - _Requirements: 6.2_
  - [x] 8.3 `ReviewService::approve`: batas 0≤disetujui≤diajukan; alasan wajib bila kurang; set `disetujui` (final), `dinilai_pada`; audit `task.approve`. (`ReviewException` + flash error.)
    - _Requirements: 6.3, 6.4, 6.5, 10.1_
  - [x] 8.4 `ReviewService::reject`: catatan wajib; set `ditolak`; audit `task.reject`.
    - _Requirements: 6.6, 10.1_
  - [x] 8.5 `TaskReportPolicy::review/reviewView` (Kabid = snapshot `kabid_sikep_user_id`, hanya status diajukan) + route `role:kabid` + menu sidebar.
    - _Requirements: 6.7, 9.2, 9.4_
  - [x] 8.6 Feature test: approve penuh/sebagian (alasan wajib), reject (catatan wajib), guard Kabid lain 403, immutability disetujui, approve melebihi diajukan ditolak (8 test pass).
    - _Requirements: 6.3, 6.4, 6.6, 6.7, 7.5_
  - Catatan: route `bukti.show` + `AttachmentController` dibuat di sini (akses pemilik/Kabid/Admin); otorisasi granular diperketat pada Tugas 11.

- [ ] 9. Siklus revisi setelah ditolak
  - [x] 9.1 Edit tugas `ditolak` diizinkan (Policy `isEditable`); `TaskService::submit` menangani pengajuan ulang `ditolak → diajukan` dengan validasi penuh; audit `task.submit` mempertahankan riwayat. (Diimplementasikan di Tugas 7.)
    - _Requirements: 7.1, 7.2, 7.3_
  - [x] 9.2 Tugas `disetujui` immutable (Policy menolak update/delete/submit/review) — terverifikasi test. Tugas `ditolak` yang dibiarkan tidak masuk rekap (ditegakkan `RecapService` di Tugas 10: hanya status `disetujui`).
    - _Requirements: 7.4, 7.5_
  - [x] 9.3 Feature test siklus revisi: ditolak → edit → resubmit → diajukan; jejak audit `task.reject` lalu `task.submit` tersimpan append-only; immutability disetujui bagi Katimker (2 test pass).
    - _Requirements: 7.2, 7.3_

- [x] 10. Rekap jam per periode
  - [x] 10.1 `RecapService::forPeriod` (utama vs tambahan, hanya `disetujui`, SUM `durasi_disetujui_menit` group by jenis) + `forKatimkerList` untuk Kabid/Admin.
    - _Requirements: 8.1, 8.2, 8.5_
  - [x] 10.2 `RecapController` (katimker/kabid/admin) + route `*.rekap.index` + halaman `Rekap/Index` (pemilih tahun/bulan, kartu ringkasan Katimker, tabel Kabid/Admin, format jam+menit, label simulasi) + menu sidebar.
    - _Requirements: 8.3, 8.4, 9.1, 9.2_
  - [x] 10.3 Test: hanya `disetujui` dihitung & dipisah per jenis, periode lain tidak ikut, Katimker lihat milik sendiri, Kabid lihat binaan saja, Katimker 403 ke rekap admin (5 test pass).
    - _Requirements: 8.2, 8.4_

- [x] 11. Akses bukti terlindungi & isolasi data
  - [x] 11.1 `TaskAttachmentPolicy::view` (pemilik tugas / Kabid snapshot / Admin via `before`) + `AttachmentController@show` memakai `authorize('view', ...)` lalu stream dari disk privat (404 bila file hilang).
    - _Requirements: 9.5_
  - [x] 11.2 Feature test: pemilik boleh, Kabid penilai boleh, Admin boleh, pihak lain 403, Kabid lain 403 (5 test pass).
    - _Requirements: 9.4, 9.5_

- [x] 12. Audit (tampilan Admin) & finalisasi
  - [x] 12.1 `AuditLogController` + route `admin.audit.index` + halaman `Admin/AuditLog` (DataTable, filter aktor/bulan/tahun, nama aktor dari SIKEP, ringkasan meta, paginasi) + menu sidebar.
    - _Requirements: 10.4_
  - [x] 12.2 Error handling integrasi SIKEP: `RoleResolver` degradasi anggun saat SIKEP gagal (QueryException di-`report`, peran SIKEP kosong, Admin lokal tetap resolve) — berjalan di setiap request. Login sudah menangani kegagalan koneksi. Unit test `RoleResolverTest`.
    - _Requirements: 1.6, 11.4_
  - [x] 12.3 `php artisan test` (52 pass) + `npm run build` (sukses); tidak ada artefak sementara; verifikasi akhir terhadap requirements.
    - _Requirements: semua_
