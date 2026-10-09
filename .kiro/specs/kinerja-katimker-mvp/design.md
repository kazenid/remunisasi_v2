# Design — Kinerja Katimker MVP (Remunisasi v2)

## Overview

Dokumen ini menjabarkan desain teknis MVP alur **Katimker mencatat tugas → Kabid menilai per-tugas**, sebagai ekstensi read-only dari SIKEP. Desain mengikuti `requirements.md` dan `docs/MVP-keputusan-desain.md`.

Prinsip utama:

- **SIKEP read-only.** Semua akses ke `bangil_kepegawaian` lewat satu koneksi khusus yang tidak pernah menulis.
- **Data lokal minimal.** Hanya menyimpan `sikep_user_id` sebagai referensi, bukan menyalin master pegawai.
- **Durasi sebagai menit integer.** Menghindari galat pembulatan jam desimal.
- **Keputusan per-tugas** dengan jejak audit append-only.

### Technology Stack

| Lapisan | Teknologi |
|---|---|
| Backend | Laravel 13 (PHP 8.3) |
| Frontend | Inertia.js + Vue 3 + PrimeVue |
| Build | Vite |
| DB aplikasi | MySQL `remunisasi_v2` (localhost), koneksi `mysql` (read-write) |
| DB sumber | MySQL `bangil_kepegawaian` (10.20.1.116:2651), koneksi `sikep` (read-only) |
| Penyimpanan bukti | Laravel filesystem disk privat (`storage/app/private`) |

---

## Architecture

```
┌───────────────────────────────────────────────────────────────┐
│                        Browser (Vue 3 + PrimeVue)              │
│   Login · Katimker (tugas, rekap) · Kabid (penilaian) · Admin  │
└───────────────────────────┬───────────────────────────────────┘
                            │ Inertia
┌───────────────────────────▼───────────────────────────────────┐
│                         Laravel 13                             │
│  Controllers → Form Requests → Services → Models → Policies    │
│                                                                │
│  Auth: SikepAuthService (verifikasi md5 berlapis)              │
│  Domain: TaskService, ReviewService, ApproverMappingService    │
│          RecapService, AuditLogger                             │
└───────┬───────────────────────────────────┬───────────────────┘
        │ conn "mysql" (RW)                 │ conn "sikep" (RO)
┌───────▼───────────────────┐   ┌───────────▼───────────────────┐
│  remunisasi_v2            │   │  bangil_kepegawaian (SIKEP)    │
│  users, task_reports,     │   │  ms_user, ms_jabatan_lapangan, │
│  task_attachments,        │   │  ms_jabatan_lapangan_user,     │
│  approver_mappings,       │   │  ms_group_user, ms_unit,       │
│  periods, audit_logs      │   │  ms_unit_user, absensi…        │
└───────────────────────────┘   └────────────────────────────────┘
```

### Konfigurasi dua koneksi (`config/database.php`)

- `mysql`: default, membaca `DB_*` dari `.env` → database `remunisasi_v2`.
- `sikep`: koneksi kedua, membaca `SIKEP_DB_*` dari `.env` → database `bangil_kepegawaian`. Opsi koneksi di-set agar hanya untuk read; migration dikecualikan dari koneksi ini.

`.env` tambahan:

```
SIKEP_DB_HOST=localhost
SIKEP_DB_PORT=3306
SIKEP_DB_DATABASE=bangil_kepegawaian
SIKEP_DB_USERNAME=root
SIKEP_DB_PASSWORD=        # dari koneksi.php SIKEP
SIKEP_PASS_SALT=abc123
```

**Penjagaan read-only.** Koneksi `sikep` hanya dipakai oleh model Eloquent read-only (lihat §Models). Tidak ada migration yang menargetkan koneksi `sikep` (perintah `migrate` selalu memakai koneksi default). Model SIKEP meng-override `save/delete` untuk melempar exception sebagai lapis pengaman tambahan.

---

## Autentikasi SIKEP

### Algoritma verifikasi password

Hash yang dipakai (**tervalidasi terhadap hash nyata di `ms_user`**):

```
password_enc = md5( SALT_1 . md5(password_plaintext) . SALT_2 )
dengan SALT_1 = "cba654", SALT_2 = "" (kosong)
→ efektif: md5( "cba654" . md5(password) )
```

> **Catatan validasi (sudah dilakukan):** formula dikunci memakai dua fakta dari kode SIKEP (`update_password_all.php`): plaintext standar `@abc123#` → hash `4f106daf068fd4e7e0530269d00167f4`, dan `abc123` → `6b81f806ca469ed8ad0132e162f889a8`. Hanya kombinasi `SALT_1="cba654"`, `SALT_2=""` yang mencocokkan **kedua** hash. Formula di `md5_php5.php` (`abc123`…`cba654`) ternyata skrip demo yang tidak dipakai untuk generate password di DB; yang berlaku adalah kondisi `koneksi.php` (pengacak_1 ditimpa `cba654`, pengacak_2 undefined). `SikepAuthService` tetap menyimpan kedua salt sebagai konfigurasi.

### Alur login

```
POST /login (username|no_absen, password)
   │
   ├─ SikepAuthService::attempt()
   │     ├─ query ms_user WHERE (username=? OR no_absen=?) AND aktif=1   [conn sikep, RO]
   │     ├─ hitung password_enc, bandingkan dengan ms_user.password
   │     ├─ tolak master password hardcode (sdmjaya/kiduldalem)
   │     └─ gagal → pesan generik
   │
   ├─ RoleResolver::resolve(sikep_user_id)
   │     ├─ Katimker  : ms_jabatan_lapangan_user.id_jabatan_lapangan = 3
   │     ├─ Kabid     : id_jabatan_lapangan IN (10,11,12)
   │     └─ Admin     : flag di tabel lokal users (is_admin)
   │
   └─ LocalUser::syncFromSikep()  → buat/udpate baris users lokal, buat sesi
```

Password plaintext tidak pernah disimpan. User lokal hanya menyimpan referensi + atribut tampilan.

---

## Models

### Model read-only SIKEP (koneksi `sikep`)

Base class `SikepModel` (abstract):

- `protected $connection = 'sikep';`
- `public $timestamps = false;`
- Override `save()`, `delete()`, `insert()`, `update()` untuk melempar `RuntimeException('SIKEP is read-only')`.

Turunan:

| Model | Tabel | Guna |
|---|---|---|
| `SikepUser` | `ms_user` | identitas, verifikasi login, nama/gelar |
| `SikepJabatanLapanganUser` | `ms_jabatan_lapangan_user` | penentuan role |
| `SikepGroupUser` | `ms_group_user` | penguat role (opsional) |
| `SikepUnitUser` / `SikepUnit` | `ms_unit_user`, `ms_unit` | tampilkan unit pegawai |

`SikepUser` menyediakan scope/metode: `findByLogin($login)`, accessor `nama_lengkap` (gabungan gelar + nama), `isKatimker()`, `isKabid()`.

### Model lokal (koneksi `mysql`)

| Model | Tabel | Relasi |
|---|---|---|
| `User` | `users` | hasMany TaskReport; punya `sikep_user_id`, `is_admin` |
| `TaskReport` | `task_reports` | belongsTo pemilik (via sikep_user_id); hasMany TaskAttachment |
| `TaskAttachment` | `task_attachments` | belongsTo TaskReport |
| `ApproverMapping` | `approver_mappings` | one-to-one Katimker→Kabid |
| `Period` | `periods` | — |
| `AuditLog` | `audit_logs` | polimorfik ringan (subject_type/subject_id) |

> Catatan relasi: karena pemilik tugas & approver direferensikan via `sikep_user_id` (bukan FK ke tabel SIKEP), relasi antar-koneksi tidak memakai join lintas-DB; data pegawai di-resolve lewat `SikepUser` saat dibutuhkan (query terpisah / batch by id).

---

## Data Model (DB `remunisasi_v2`)

### `users`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | PK | |
| sikep_user_id | unsigned int, unik | referensi `ms_user.id` |
| username | string | mirror |
| nama | string | mirror (nama lengkap) |
| no_absen | int, nullable | mirror |
| is_admin | boolean, default false | peran Admin lokal |
| last_sync_at | timestamp, nullable | sinkronisasi terakhir |
| timestamps | | |

### `periods`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | PK | |
| tahun | smallint | |
| bulan | tinyint | 1–12 |
| cut_off | date, nullable | default tgl 10 |
| target_cair | date, nullable | default tgl 15 |
| status | enum(`terbuka`,`terkunci`) default `terbuka` | penguncian cut-off di luar MVP |
| unik (tahun,bulan) | | |

### `task_reports`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | PK | |
| period_id | FK periods, nullable | diturunkan dari tanggal |
| katimker_sikep_user_id | unsigned int, index | pemilik |
| kabid_sikep_user_id | unsigned int, nullable, index | penilai (snapshot saat diajukan) |
| tanggal | date | tanggal kegiatan |
| jenis | enum(`utama`,`tambahan`) | |
| judul | string | |
| uraian | text, nullable | opsional |
| durasi_diajukan_menit | unsigned int | > 0 |
| durasi_disetujui_menit | unsigned int, nullable | 0..diajukan, diisi Kabid |
| status | enum(`draf`,`diajukan`,`disetujui`,`ditolak`) default `draf` | |
| catatan_penilai | text, nullable | catatan approve/tolak |
| alasan_pengurangan | text, nullable | wajib bila disetujui<diajukan atau 0 |
| lat, lng | decimal, nullable | koordinat opsional |
| akurasi | float, nullable | |
| diajukan_pada | timestamp, nullable | |
| dinilai_pada | timestamp, nullable | |
| timestamps | | |

Index tambahan: (`katimker_sikep_user_id`,`status`), (`kabid_sikep_user_id`,`status`), (`tanggal`).

### `task_attachments`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | PK | |
| task_report_id | FK task_reports, cascade | |
| tipe | enum(`foto_selfie`,`dokumen`) | |
| path | string | lokasi di disk privat |
| nama_asli | string | |
| mime | string | |
| ukuran | unsigned int | byte |
| diambil_pada | timestamp, nullable | |
| timestamps | | |

### `approver_mappings`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | PK | |
| katimker_sikep_user_id | unsigned int, **unik** | one-to-one |
| kabid_sikep_user_id | unsigned int, index | |
| aktif | boolean default true | |
| berlaku_mulai | date, nullable | perubahan efektif (opsional) |
| diubah_oleh | unsigned int, nullable | sikep_user_id admin |
| timestamps | | |

### `audit_logs`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | PK | |
| aktor_sikep_user_id | unsigned int, nullable | |
| aksi | string | mis. `task.submit`, `task.approve`, `task.reject`, `task.resubmit`, `mapping.update` |
| subject_type | string | mis. `TaskReport`, `ApproverMapping` |
| subject_id | unsigned int | |
| meta | json, nullable | status lama→baru, durasi, alasan, nilai lama→baru |
| created_at | timestamp | append-only (tanpa updated_at) |

---

## State Machine — Status Tugas

```
          submit                       approve
 ┌─ draf ──────────▶ diajukan ───────────────────▶ disetujui (final)
 │     ▲                 │
 │     │ edit            │ reject (+catatan wajib)
 │     │                 ▼
 │     │              ditolak
 │     │ edit             │
 │     └─────────────────┘ resubmit (validasi ulang) ──▶ diajukan
 │                        │
 └(hapus hanya saat draf) └ dibiarkan: tetap ditolak (tidak masuk rekap)
```

Transisi legal (ditegakkan di `TaskService`):

| Dari | Aksi | Ke | Guard |
|---|---|---|---|
| draf | update/delete | draf/— | pemilik, status draf |
| draf | submit | diajukan | durasi>0, ada foto_selfie, ada approver mapping |
| ditolak | update | ditolak | pemilik |
| ditolak | resubmit | diajukan | validasi submit |
| diajukan | approve | disetujui | kabid pemetaan, 0≤disetujui≤diajukan, alasan bila kurang |
| diajukan | reject | ditolak | kabid pemetaan, catatan wajib |
| disetujui | — | — | immutable |

Setiap transisi memanggil `AuditLogger::record(...)`.

---

## Services

### `SikepAuthService`
- `attempt(string $login, string $password): ?SikepUser` — verifikasi hash, tolak master password, hanya `aktif=1`.
- `hash(string $password): string` — `md5(salt1 . md5($password) . salt2)` dengan salt dari config.

### `RoleResolver`
- `resolve(int $sikepUserId): array{roles, isKatimker, isKabid, isAdmin}` — query `ms_jabatan_lapangan_user` (acuan utama) + flag admin lokal.

### `TaskService`
- `createDraft`, `updateDraft`, `deleteDraft`, `submit`, `resubmit` — menegakkan state machine & validasi; menyertakan resolusi `kabid_sikep_user_id` dari `ApproverMapping` saat submit.

### `ReviewService`
- `listForKabid(int $kabidId, filters)`, `approve(taskId, menitDisetujui, alasan?, catatan?)`, `reject(taskId, catatan)` — guard kepemilikan via mapping.

### `ApproverMappingService`
- `listKatimker()` (dari SIKEP), `assign(katimkerId, kabidId)`, `unassigned()` (Katimker tanpa mapping). Validasi target harus Kabid.

### `RecapService`
- `forPeriod(sikepUserId, tahun, bulan): {utama_menit, tambahan_menit}` — hanya status `disetujui`.

### `AuditLogger`
- `record(aktorId, aksi, subject, meta)` — insert append-only.

---

## Otorisasi (Policies)

- `TaskReportPolicy`: `view/update/delete/submit` hanya pemilik (katimker_sikep_user_id == user); `review/approve/reject` hanya Kabid yang dipetakan ke pemilik.
- `ApproverMappingPolicy`: hanya Admin.
- `AttachmentPolicy`: akses file hanya pemilik, Kabid penilai, Admin. File disajikan lewat controller terkontrol (bukan URL publik), dari disk privat.
- Middleware peran untuk grup route (katimker / kabid / admin).

---

## Antarmuka (Inertia Pages)

| Route | Halaman Vue | Peran |
|---|---|---|
| `/login` | `Auth/Login` | publik |
| `/katimker/tugas` | `Katimker/TaskList` | Katimker |
| `/katimker/tugas/create` | `Katimker/TaskForm` | Katimker |
| `/katimker/tugas/{id}/edit` | `Katimker/TaskForm` | Katimker (draf/ditolak) |
| `/katimker/rekap` | `Katimker/Recap` | Katimker |
| `/kabid/penilaian` | `Kabid/ReviewList` | Kabid |
| `/kabid/penilaian/{id}` | `Kabid/ReviewDetail` | Kabid |
| `/admin/pemetaan` | `Admin/ApproverMapping` | Admin |
| `/admin/audit` | `Admin/AuditLog` | Admin |

Komponen pendukung: input durasi jam+menit (`DurationInput`), penangkap selfie (`SelfieCapture` via `getUserMedia`, fallback upload file), unggah dokumen, penampil bukti terlindungi.

---

## Penanganan Bukti (Foto Selfie & Dokumen)

- Disimpan di disk privat `storage/app/private/bukti/{period}/{task_id}/`.
- Validasi: foto `jpeg/png`, dokumen `pdf` (sesuai kebiasaan SIKEP `pdf`), batas ukuran dikonfigurasi (default saran 5 MB/foto, 10 MB/dokumen — final saat build).
- Minimal satu `foto_selfie` sebelum submit (ditegakkan di `TaskService::submit`).
- Koordinat (bila ada) disimpan di `task_reports`, bukan EXIF-dependent.
- Akses via `GET /bukti/{attachment}` yang mengecek `AttachmentPolicy` lalu stream file.

---

## Error Handling

- **Integrasi SIKEP gagal:** tangkap `QueryException`/koneksi pada layer service; tampilkan pesan "integrasi SIKEP tidak tersedia" pada operasi terdampak; operasi lokal yang tak bergantung SIKEP tetap jalan. Login gagal → tidak membuat sesi.
- **Validasi:** Form Request mengembalikan pesan spesifik (durasi, bukti kurang, alasan wajib). Transisi ilegal → `DomainException` dipetakan ke 422 dengan pesan jelas.
- **Otorisasi:** pelanggaran Policy → 403.
- **Upload:** kegagalan simpan file membatalkan transaksi tugas (DB transaction membungkus pembuatan tugas + lampiran).

---

## Testing Strategy

- **Unit:** `SikepAuthService::hash` (vektor uji dari sampel nyata), transisi `TaskService` (legal/ilegal), `RecapService` (hanya status disetujui, pemisahan utama/tambahan), batas durasi disetujui.
- **Feature:** alur login (sukses/gagal/akun nonaktif/master password ditolak), submit (validasi bukti & mapping), approve/reject (guard mapping, alasan wajib), resubmit setelah ditolak, isolasi data antar peran (403), akses file bukti terlindungi.
- **Integrasi SIKEP:** diuji dengan koneksi `sikep` ke basis uji/replika; penegasan tidak ada operasi tulis (model read-only melempar exception).
- **Catatan:** test ditulis saat implementasi tiap tugas, bukan sekaligus; jangan tambah test di luar yang relevan dengan tugas berjalan.

---

## Butir Verifikasi Teknis (saat implementasi)

1. **Formula hash password** divalidasi terhadap sampel `ms_user.password` nyata (lihat catatan di §Autentikasi). Sesuaikan urutan/isi salt bila perlu, lewat config.
2. Keandalan penanda role (`ms_jabatan_lapangan_user` vs `ms_group_user`) pada data nyata.
3. Konektivitas & kredensial ke `10.20.1.116:2651` dari lingkungan aplikasi.
4. Charset `latin1` pada tabel SIKEP — pastikan koneksi `sikep` membaca nama/teks dengan encoding benar.
