# Implementation Plan — Kinerja Tahap 2 (Fitur Lanjutan)

Dikerjakan berurutan; verifikasi (test/build) tiap langkah. Rujukan `_Requirements:_` mengacu ke `requirements.md`. Mesin perhitungan insentif final TIDAK termasuk.

- [x] 1. Komponen kehadiran dari SIKEP
  - [x] 1.1 Model read-only `SikepPenguranganInsentif` (+ query langsung via koneksi `sikep`). _Requirements: 1.7_
  - [x] 1.2 `AttendanceService::nilai()` mereplikasi `setting_absensi_rekap.php` → `persen_kehadiran` + rincian. _Requirements: 1.1–1.6, 1.8_
  - [x] 1.3 Diverifikasi pada data nyata (3 pegawai, Jun 2026). _Requirements: 1.9_
  - [x] 1.4 Dipakai di Rekap Katimker, label persiapan; feature test. _Requirements: 1.6, 5.3_

- [x] 2. Pemindahan klasifikasi (utama ↔ tambahan)
  - [x] 2.1 `ReviewService::ubahJenis()` + integrasi `approve()`; alasan wajib; audit `task.reclassify`. _Requirements: 2.1, 2.2, 2.3, 2.5_
  - [x] 2.2 Route + `ReviewController::ubahJenis`; guard Policy `review`. _Requirements: 2.4_
  - [x] 2.3 UI `Kabid/ReviewDetail`. _Requirements: 2.1, 2.2_
  - [x] 2.4 Feature test (5 pass). _Requirements: 2.3, 2.4, 2.5, 2.6_

- [x] 3. Penugasan tambahan oleh Kabid
  - [x] 3.1 Migration `dibuat_oleh_sikep_user_id` + `jenis_terkunci`; status `menunggu_bukti` (kolom status → string). _Requirements: 3.1, 3.2, 3.3_
  - [x] 3.2 `AssignmentService::createForKatimker()` + guard pemetaan; audit `task.assign`. _Requirements: 3.1, 3.3, 3.6, 3.7_
  - [x] 3.3 Katimker edit saat `menunggu_bukti`; jenis terkunci dipaksa; `submit` dari `menunggu_bukti`. _Requirements: 3.4, 3.5_
  - [x] 3.4 Controller + UI Kabid `Penugasan` + penanda di `TaskList`. _Requirements: 3.1, 3.6_
  - [x] 3.5 Feature test (3 pass). _Requirements: 3.2, 3.4, 3.5, 3.6_

- [x] 4. Target global & per individu
  - [x] 4.1 Migration + model `IndividualTarget`; `TargetResolver::efektif()`. _Requirements: 4.1–4.4_
  - [x] 4.2 `IndividualTargetController@store` + route admin & kabid; otorisasi; audit `target.update`. _Requirements: 4.5, 4.6_
  - [x] 4.3 Target efektif + capaian (cap 100%) di Rekap Katimker, label persiapan. _Requirements: 4.3, 4.7, 5.3_
  - [x] 4.4 Test (4 pass). _Requirements: 4.3, 4.5_

- [x] 5. Finalisasi
  - [x] 5.1 `php artisan test` (75 pass) + `npm run build` (sukses); non-regresi; tanpa artefak. _Requirements: 5.1, 5.2_

> Catatan: UI khusus set target individu masih via endpoint (`*.target.store`); form admin/kabid dapat ditambah kemudian. Mesin perhitungan insentif final tetap DITUNDA.
