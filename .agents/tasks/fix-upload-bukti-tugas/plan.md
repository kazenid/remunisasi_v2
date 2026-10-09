# Implementation Plan — Perbaikan Upload Bukti Tugas (Katimker)

Konteks: Laravel 13 + Inertia 3 + Vue 3 (PrimeVue Aura/Sakai + Tailwind v4). UI bahasa Indonesia.
Disk bukti privat = `local`. `TaskAttachment` sudah `hasMany` dari `TaskReport` (lihat migrasi `2026_10_06_100004_create_task_attachments_table.php`), jadi **tidak perlu migrasi** untuk multi-file — satu record per file sudah didukung skema saat ini. Kolom `tipe` adalah enum `['foto_selfie','dokumen']`; kita pertahankan enum ini (file campuran dipetakan ke `dokumen` untuk non-gambar dan `foto_selfie` untuk gambar), sehingga **tidak ada perubahan skema**.

Perintah build & test proyek (dari `composer.json` / `package.json`):
- Test backend: `php artisan test` (PHPUnit). Untuk fokus modul: `php artisan test --filter=KatimkerTaskTest` dan `php artisan test --filter=KatimkerSubmitTest`.
- Build frontend: `npm run build` (Vite). Komponen PrimeVue auto-registered via `unplugin-vue-components` + `PrimeVueResolver` (vite.config.js) — jangan import manual.

## Keputusan desain (ringkas)

- **Satu field unggah campuran `bukti[]`** ditambahkan di samping `foto_selfie[]` dan `dokumen[]` yang lama. Alasan: alur selfie wajib (SelfieCapture → `foto_selfie[]`) dan syarat `hasSelfie()` pada `submit()` harus dipertahankan; menambah `bukti[]` memenuhi permintaan "upload foto dan dokumen bersamaan, lebih dari satu" tanpa membongkar alur selfie/submit. File di `bukti[]` yang bertipe gambar disimpan sebagai `tipe=foto_selfie` (agar ikut menghitung `hasSelfie()`), selebihnya sebagai `tipe=dokumen`.
- **Pemetaan tipe berdasarkan MIME** di service: gambar (`image/*`) → `foto_selfie`, lainnya (pdf/doc/docx/xls/xlsx) → `dokumen`. Alasan: enum DB hanya punya dua nilai; ini menjaga kompatibilitas dan semantik selfie.
- **Pembungkusan penyimpanan dalam try/catch** di `TaskService::simpanSatu`, melempar `RuntimeException` berpesan Indonesia bila `Storage` gagal; controller menangkap dan mengembalikan `back()->withErrors()` + flash `error`. Alasan: kegagalan I/O harus jadi pesan terbaca, bukan 500.
- **Props edit diperkaya** lewat accessor pada model (bukan query mentah di controller) agar konsisten dan mudah diuji: tiap attachment menyertakan `url` (route `bukti.show`), `is_image`, `ukuran_label`. Alasan: frontend butuh URL aman + flag preview; menaruhnya di model menjaga controller tipis.
- **Toast + InlineMessage di TaskForm.vue**: tambah `onError` pada submit (menampilkan error umum), watch `flash.error`, dan render `Message`/`small` per-field. Alasan: form tidak boleh diam saat gagal.
- Batas ukuran: gambar maks 5 MB, dokumen maks 10 MB (ikut nilai lama). Mimetypes dokumen diperluas: `pdf,doc,docx,xls,xlsx`.

---

- [ ] 1. Perluas validasi `TaskDraftRequest` untuk field campuran `bukti[]` dan dokumen multi-tipe, dengan pesan error Indonesia yang spesifik.
      Tambahkan aturan: `bukti` => `['nullable','array']`; `bukti.*` => `['file','max:10240','mimes:jpeg,jpg,png,pdf,doc,docx,xls,xlsx']`. Ubah `dokumen.*` menjadi `['file','max:10240','mimes:pdf,doc,docx,xls,xlsx']` (tetap dukung PDF lama + dokumen umum). Pertahankan `foto_selfie.*` (image, max 5120). Tambah entri `messages()`: `bukti.*.mimes` => 'Tipe file tidak didukung (hanya jpg, png, pdf, doc, docx, xls, xlsx).', `bukti.*.max` => 'Ukuran file maksimal 10 MB.', `bukti.*.file` => 'Berkas bukti tidak valid.', `dokumen.*.mimes` => 'Dokumen harus berformat pdf, doc, docx, xls, atau xlsx.', dan pertahankan pesan `foto_selfie.*`. Catatan: `max` dalam KB (10240 = 10 MB).
      Files: app/Http/Requests/TaskDraftRequest.php
      Verify: `php artisan test --filter=KatimkerTaskTest` — test validasi tipe/ukuran (langkah 6) lulus.

- [ ] 2. Perbarui `TaskService` agar menerima dan menyimpan file campuran, memetakan tipe berdasarkan MIME, dan membungkus penyimpanan dalam try/catch dengan pesan Indonesia.
      Di `simpanLampiran`, tambahkan loop ketiga untuk `($files['bukti'] ?? [])` yang memanggil helper baru `tipeUntuk(UploadedFile $file): string` (mengembalikan `TaskAttachment::TIPE_FOTO_SELFIE` bila `str_starts_with($file->getMimeType(), 'image/')`, selain itu `TIPE_DOKUMEN`). Di `simpanSatu`, bungkus `$file->store(...)` dan `attachments()->create(...)` dalam try/catch; pada kegagalan `Storage`/`Throwable`, lempar `\RuntimeException` berpesan "Gagal menyimpan file \"{nama_asli}\" ke penyimpanan." Jangan ubah struktur direktori `bukti/{Y-m}/{id}`.
      Files: app/Services/TaskService.php
      Verify: `php artisan test --filter=KatimkerTaskTest` — test upload multi-file campuran (langkah 6) lulus.

- [ ] 3. Tangani kegagalan simpan di controller `store`/`update` dan kirim file `bukti` ke service.
      Bungkus pemanggilan `$this->service->createDraft(...)` dan `updateDraft(...)` dengan try/catch `\RuntimeException`; pada tangkapan, `return back()->withErrors(['bukti' => $e->getMessage()])->with('error', 'Tugas gagal disimpan: '.$e->getMessage())`. Tambahkan `'bukti' => $request->file('bukti', [])` ke array files di kedua method. Pertahankan `foto_selfie` dan `dokumen`.
      Files: app/Http/Controllers/Katimker/TaskController.php
      Verify: `php artisan test --filter=KatimkerTaskTest` — seluruh test modul (termasuk create/edit/hapus lama) tetap lulus.

- [ ] 4. Tambah accessor pada `TaskAttachment` untuk props edit: `url`, `is_image`, `ukuran_label`; append ke serialisasi.
      Tambahkan `protected $appends = ['url','is_image','ukuran_label'];` dan accessor: `getUrlAttribute()` => `route('bukti.show', $this->id)`; `getIsImageAttribute()` => `str_starts_with((string) $this->mime, 'image/')`; `getUkuranLabelAttribute()` => format KB/MB manusiawi (mis. `< 1024` → "{n} B", `< 1048576` → "{n} KB", selain itu "{n} MB"). Pastikan `url` memakai nama route `bukti.show` yang sudah ada.
      Files: app/Models/TaskAttachment.php
      Verify: `php artisan test --filter=KatimkerTaskTest` — test "edit memuat daftar bukti" (langkah 6) memeriksa props `url`/`is_image` lulus.

- [ ] 5. Pastikan `edit` mengirim attachments yang sudah diperkaya (akibat accessor langkah 4) — verifikasi, sesuaikan bila perlu.
      Method `edit` sudah `->load('attachments')`; dengan `$appends` di langkah 4, setiap attachment otomatis membawa `url`, `is_image`, `ukuran_label`, `nama_asli`, `ukuran`, `tipe`. Tidak ada perubahan kode wajib di sini kecuali konfirmasi relasi termuat; bila ingin eksplisit, boleh urutkan `->load(['attachments' => fn($q) => $q->orderBy('id')])`.
      Files: app/Http/Controllers/Katimker/TaskController.php
      Verify: `php artisan test --filter=KatimkerTaskTest` — test edit (langkah 6) lulus.

- [ ] 6. Tambah/perbarui test di `KatimkerTaskTest.php` untuk tiga skenario inti.
      Tambahkan: (a) `test_upload_bukti_campuran_membuat_beberapa_attachment` — POST store dengan `bukti` = [UploadedFile::fake()->image('f.jpg'), UploadedFile::fake()->create('d.pdf', 100, 'application/pdf')]; assert `attachments()->count()===2`, satu `tipe=foto_selfie` (gambar) dan satu `tipe=dokumen`. (b) `test_bukti_tipe_tidak_didukung_ditolak` — POST dengan `bukti` = [UploadedFile::fake()->create('x.exe',10,'application/octet-stream')]; `assertSessionHasErrors('bukti.0')`. (c) `test_bukti_melebihi_ukuran_ditolak` — `UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')` (>10 MB); `assertSessionHasErrors('bukti.0')`. (d) `test_edit_memuat_daftar_bukti_dengan_url` — buat tugas milik user + 1 attachment via store, GET `katimker.tugas.edit`, gunakan `Inertia\Testing\AssertableInertia` untuk assert prop `tugas.attachments.0.url` tidak null dan `is_image` boolean. Pakai `Storage::fake('local')` di tiap test yang menyimpan file. Pertahankan `test_dokumen_non_pdf_ditolak` dan sesuaikan bila perlu agar tetap mengirim lewat `dokumen[]`.
      Files: tests/Feature/KatimkerTaskTest.php
      Verify: `php artisan test --filter=KatimkerTaskTest` — semua test (lama + baru) lulus.

- [ ] 7. Pastikan `KatimkerSubmitTest` tetap hijau karena `hasSelfie()` kini bisa terisi via `bukti[]` gambar.
      Tambahkan satu test opsional `test_bukti_gambar_memenuhi_syarat_selfie_saat_submit`: store tugas dengan `bukti`=[image], petakan approver, lalu submit → `assertRedirect` dan status `diajukan` (karena gambar di `bukti[]` disimpan sebagai `foto_selfie`). Jalankan juga suite submit lama tanpa modifikasi perilaku.
      Files: tests/Feature/KatimkerSubmitTest.php
      Verify: `php artisan test --filter=KatimkerSubmitTest` — semua test lulus.

- [ ] 8. Tambah field unggah campuran `bukti[]` di `TaskForm.vue` memakai PrimeVue FileUpload (multiple, accept gambar+dokumen), gaya Sakai.
      Tambahkan `bukti: []` ke `useForm`. Tambahkan blok FileUpload baru di kartu "Bukti": `mode="basic"`, `name="bukti[]"`, `accept="image/jpeg,image/png,application/pdf,.doc,.docx,.xls,.xlsx"`, `:maxFileSize="10485760"`, `multiple`, `customUpload`, `:auto="false"`, `chooseLabel="Pilih Foto/Dokumen"`, handler `@select="onBukti"` yang set `form.bukti = Array.from(event.files ?? [])`. Tampilkan daftar file terpilih sebagai `Chip` yang bisa dihapus (removable), mirip pola `selfieBaru`. Pertahankan SelfieCapture (`foto_selfie`) dan dokumen PDF lama bila diinginkan, atau arahkan dokumen lama ke `bukti` — minimal `bukti[]` harus ada. Pertahankan tipografi/`.card`/grid Sakai.
      Files: resources/js/Pages/Katimker/TaskForm.vue
      Verify: `npm run build` — build Vite sukses tanpa error.

- [ ] 9. Tampilkan notifikasi error jelas di `TaskForm.vue`: `onError` pada submit, watch `flash.error`, dan `Message`/`small` per-field untuk `bukti`.
      Pada `opts` submit tambahkan `onError: (errors) => { const first = Object.values(errors)[0]; toast.add({ severity: 'error', summary: 'Gagal menyimpan', detail: first ?? 'Periksa isian yang ditandai merah.', life: 6000 }); }`. Import `usePage` dan `watch`; watch `page.props.flash?.error` untuk menampilkan toast error (immediate), seperti pola di `TaskList.vue`. Tambahkan `<small v-if="form.errors.bukti" class="text-red-500">{{ form.errors.bukti }}</small>` di bawah FileUpload bukti. Pertahankan toast sukses `onSuccess` yang sudah ada.
      Files: resources/js/Pages/Katimker/TaskForm.vue
      Verify: `npm run build` — build sukses; verifikasi manual: submit dengan file tak didukung memunculkan toast + pesan merah.

- [ ] 10. Perbarui tampilan "Lampiran tersimpan" di mode edit `TaskForm.vue`: thumbnail untuk gambar, ikon+nama+ukuran untuk dokumen, link buka via `url`, tombol hapus dengan konfirmasi.
      Ganti daftar `<ul>` lama: untuk tiap `att` di `tugas.attachments`, bila `att.is_image` render `<img :src="att.url">` kecil (preview) + nama + `att.ukuran_label`; bila bukan, render ikon dokumen (`pi pi-file-pdf`/`pi pi-file`) + nama + ukuran. Nama/preview dibungkus `<a :href="att.url" target="_blank">` (buka via route `bukti.show`). Tombol hapus memanggil `useConfirm().require(...)` lalu `router.delete(route('katimker.tugas.lampiran.destroy', att.id), { preserveScroll:true, onSuccess: toast sukses, onError: toast gagal })`. Setelah hapus, Inertia memuat ulang props `tugas` sehingga daftar diperbarui otomatis. Pakai `useConfirm` (sudah global via ConfirmDialog di AppLayout).
      Files: resources/js/Pages/Katimker/TaskForm.vue
      Verify: `npm run build` — build sukses; verifikasi manual: buka Edit tugas yang punya bukti → preview/ikon tampil, klik nama membuka file, hapus memperbarui daftar + toast.

- [ ] 11. Jalankan verifikasi penuh.
      Jalankan seluruh test backend dan build frontend untuk memastikan tidak ada regresi lintas-berkas.
      Files: (tidak ada perubahan; langkah verifikasi)
      Verify: `php artisan test --filter=Katimker` lalu `npm run build` — keduanya sukses.

## Catatan & asumsi

- Enum `tipe` dipertahankan (tanpa migrasi). File gambar dari `bukti[]` sengaja disimpan sebagai `foto_selfie` agar `hasSelfie()` dan alur submit tetap benar; dokumen lain sebagai `dokumen`. Bila nanti diinginkan tipe generik "bukti", itu butuh migrasi enum baru — di luar lingkup bug-fix ini.
- `getMimeType()` dipakai di service (server-side, hasil deteksi), sedangkan validasi `mimes` juga server-side; keduanya konsisten untuk file hasil upload nyata. Pada test, `UploadedFile::fake()->image()` menghasilkan `image/*` dan `->create(name, size, mime)` menghormati MIME yang diberikan.
- Policy & route tidak diubah: akses bukti tetap lewat `bukti.show` + `TaskAttachmentPolicy@view`; hapus lampiran tetap `katimker.tugas.lampiran.destroy` + `update` policy pada task induk.
- Alur draft/submit/review tidak berubah.
```
