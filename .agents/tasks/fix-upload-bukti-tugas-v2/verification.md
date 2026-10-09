# Verifikasi — Fix Upload Bukti Tugas v2

Dua bug diperbaiki di alur "upload bukti tugas" (Katimker). Nilai batas dikonfirmasi dari
kode: foto selfie `max:5120` = **5 MB**, bukti `max:10240` = **10 MB**
(`app/Http/Requests/TaskDraftRequest.php`). Angka yang sama direfleksikan di pesan backend
dan cek sisi klien.

## Perubahan

### Bug 1 — pesan oversize jelas (tiga lapis)
- **Backend (pesan validasi kustom)** — `app/Http/Requests/TaskDraftRequest.php`:
  - `foto_selfie.*.max` dipertegas menjadi "Ukuran foto selfie maksimal 5 MB."
  - Ditambah pesan untuk rule implisit **`uploaded`** yang gagal saat PHP menolak file
    (`UPLOAD_ERR_INI_SIZE`, file > `upload_max_filesize` tapi POST masih ter-parse) —
    `foto_selfie.*.uploaded`, `bukti.*.uploaded`, `dokumen.*.uploaded`. Ini menggantikan
    teks generik "The foto_selfie.0 failed to upload." dengan keterangan batas MB.
  - `bukti.*.max` diperjelas "…10 MB per berkas." `rules()` tidak diubah.
- **Backend (`post_max_size` terlampaui)** — `bootstrap/app.php`: handler
  `PostTooLargeException` untuk request web (non-`api/*`) mengembalikan
  `back()->with('error', '…maks 5 MB per foto selfie, 10 MB per dokumen.')`. Jalur ini
  bergantung `php.ini` lingkungan (tidak ada override di repo) → **needs-verification-during-implementation**
  (uji manual: set `post_max_size=1M` lalu unggah > 1 MB → satu toast error berbahasa
  Indonesia, bukan halaman 413). Terdaftar dan ter-build; verifikasi otomatis via test
  properti pesan.
- **Frontend (penjaga utama, cek sisi klien)**:
  - `resources/js/Components/form/SelfieCapture.vue`: prop `maxSizeMb` (default 5), file
    > batas di-emit sebagai `tooLarge` dan **tidak** di-emit sebagai `captured` (tidak ikut
    dikirim). Berlaku untuk unggah berkas maupun hasil kamera.
  - `resources/js/Pages/Katimker/TaskForm.vue`: konstanta `MAKS_SELFIE_MB=5`,
    `MAKS_BUKTI_MB=10`. `onBukti` memfilter file oversize + toast error (sebut nama & MB);
    `FileUpload` memakai `:maxFileSize="MAKS_BUKTI_MB*1024*1024"`,
    `invalidFileSizeMessage`, dan `@error="onBuktiError()"`. `<SelfieCapture>` menerima
    `:maxSizeMb` + handler `@tooLarge`. Teks hint menyebut batas MB.

### Bug 2 — notifikasi ganda (gagal + sukses) saat tanpa bukti
- **Handler frontend yang memicu toast sukses palsu**: di
  `resources/js/Pages/Katimker/TaskList.vue`, `konfirmasiAjukan()` sebelumnya memanggil
  `router.post(...submit...)` dengan callback **`onSuccess`** yang menampilkan toast
  "Diajukan". Backend `TaskController@submit` menangani kegagalan (mis. tanpa selfie)
  dengan `return back()->with('error', ...)` — sebuah redirect 302→200 yang **dianggap
  sukses oleh Inertia**, sehingga `onSuccess` menyala BERSAMAAN dengan watcher
  `flash.error` → dua toast.
- **Cara digating sekarang**: `onSuccess` dihapus dari submit (dan dari `konfirmasiHapus`).
  Toast sukses/gagal kini **digerakkan oleh flash server** via dua watcher terpisah
  (`flash.success` dan `flash.error`) tanpa `immediate`. Server adalah sumber kebenaran:
  gagal → hanya `flash.error` → satu toast error; sukses → hanya `flash.success` → satu
  toast sukses. Karena submit gagal tidak pernah menitipkan `flash.success` (dikonfirmasi
  test), tidak ada toast sukses palsu.
- **`TaskForm.vue`**: watcher `flash.error` yang ber-`immediate: true` dihapus; kesalahan
  simpan/validasi form kini hanya lewat callback `onError` (controller memakai
  `withErrors(...)` → Inertia memanggil `onError`), sehingga tepat satu toast dan tidak ada
  toast error "basi" saat form dibuka.

## Test
Ditambahkan di `tests/Feature/KatimkerTaskTest.php`:
- `test_selfie_oversize_menampilkan_pesan_ukuran` — upload selfie 6 MB → error field
  `foto_selfie.0`, pesan mengandung "5 MB" dan BUKAN "failed to upload".
- `test_bukti_oversize_menampilkan_pesan_ukuran` — upload bukti 11 MB → error `bukti.0`,
  pesan mengandung "10 MB" dan BUKAN "failed to upload".
- `test_pesan_uploaded_kustom_terdaftar_untuk_foto_selfie` — memastikan pesan kustom rule
  `uploaded` terdaftar untuk `foto_selfie.*` (5 MB) dan `bukti.*` (10 MB), menutup kasus
  `UPLOAD_ERR_INI_SIZE` yang tidak melewati rule `max`.

Diperkuat di `tests/Feature/KatimkerSubmitTest.php`:
- `test_submit_tanpa_selfie_ditolak` — kini juga `assertSessionMissing('success')`
  (bukti sisi server untuk Bug 2: pengajuan gagal tidak pernah menitipkan flash sukses).

## Perintah yang dijalankan dan hasil
- `php artisan test --filter=Katimker` → **27 passed (122 assertions)**.
- `php artisan test` (suite penuh) → **160 passed (606 assertions)**. Baseline 157 + 3 test
  baru = 160. Tidak ada regresi.
- `npm run build` → **sukses** (`✓ built in ~3.25s`, 1107 modules). Tidak ada error.
- `vendor/bin/pint app/Http/Requests/TaskDraftRequest.php bootstrap/app.php tests/Feature/KatimkerTaskTest.php tests/Feature/KatimkerSubmitTest.php`
  → 2 style issues fixed (import PostTooLargeException + ordering), bersih.

## Catatan
- Tidak mengubah: rules validasi lain, enum `tipe`, skema DB, policy, route, alur
  draft/submit/review, field `bukti`/`dokumen` yang sudah ada. Happy path tetap satu toast
  sukses. Fix sebelumnya di `.agents/tasks/fix-upload-bukti-tugas/` tidak diregresi (test
  modul terkait tetap hijau).
- Jalur `post_max_size` butuh uji manual tergantung `php.ini`; handler sudah terpasang dan
  ter-build.
