# Perbaikan upload bukti tugas Katimker: notifikasi error, multi-file campuran, dan tampilan bukti di mode edit

Perubahan ini menutup tiga keluhan pengguna pada form tugas Katimker: kegagalan simpan/upload yang diam tanpa pesan, ketidakmampuan mengunggah foto dan dokumen sekaligus (lebih dari satu file), dan mode edit yang tidak menampilkan bukti yang sudah tersimpan. Pendekatannya menambah satu field unggah campuran `bukti[]` di samping `foto_selfie[]`/`dokumen[]` lama, membungkus penyimpanan file dalam try/catch yang melempar `RuntimeException` berpesan Indonesia, memperkaya model `TaskAttachment` dengan accessor `url`/`is_image`/`ukuran_label` untuk serialisasi Inertia, dan menambah Toast error + daftar lampiran tersimpan (preview/ikon, buka, hapus) di `TaskForm.vue`. Tidak ada perubahan skema DB — `TaskAttachment` sudah `hasMany` per `TaskReport` dan enum `tipe` dipertahankan dengan memetakan gambar campuran ke `foto_selfie` dan sisanya ke `dokumen`.

Watch for: inkonsistensi sumber MIME antara validasi (`mimes`, berbasis ekstensi), pemetaan tipe (`getMimeType()`, deteksi konten server), dan kolom `mime` tersimpan (`getClientMimeType()`, dari klien, bisa dipalsukan) — bukan pemblokir karena akses tetap digerbang policy, tapi perlu disadari (confirmed). Kegagalan `Storage` hanya dipetakan ke field error `bukti` meski file datang dari `foto_selfie`/`dokumen` juga (likely, kosmetik).

**Verdict**: APPROVED

## High-level view

Ketiga keluhan tertangani di kode secara end-to-end. Kegagalan simpan kini dibungkus try/catch di `TaskService::simpanSatu` yang melempar `RuntimeException` berpesan Indonesia menyebut nama file; `store`/`update` menangkapnya dan mengembalikan `back()->withErrors(['bukti'])->with('error', ...)`, lalu frontend menampilkannya via `onError` submit, watch `flash.error`, dan `<small>` per-field. Validasi juga memberi pesan spesifik per aturan (mimes/max/file) dalam Bahasa Indonesia.

Upload multi-file campuran bekerja lewat field `bukti[]`: request menerima array multi-tipe (`mimes:jpeg,jpg,png,pdf,doc,docx,xls,xlsx`, max 10 MB), service me-loop array dan membuat satu `TaskAttachment` per file dengan tipe dipetakan dari MIME (gambar → `foto_selfie` agar ikut menghitung `hasSelfie()`, sisanya → `dokumen`). Frontend memakai `FileUpload` `multiple` dengan `accept` campuran dan mengirim array.

Mode edit memuat relasi `attachments` (terurut) dan merender daftar bukti tersimpan: thumbnail untuk gambar, ikon untuk dokumen, nama + `ukuran_label`, dibuka via route `bukti.show`, dan dihapus via `katimker.tugas.lampiran.destroy` dengan konfirmasi + Toast. Accessor model menyediakan `url`/`is_image`/`ukuran_label` ke props Inertia.

Alur draft/submit/review dan otorisasi tetap utuh: policy `view`/`update`/`delete` pada `TaskReport` dan `view` pada `TaskAttachment` tak berubah dan tetap menggerbang semua jalur baru; penghapusan lampiran digerbang `update` pada tugas induk; penamaan route konsisten dengan konvensi grup `katimker.`. Bukti verifikasi coder mencatat 20 test Katimker lulus (95 assertions) dan `npm run build` sukses.

<details>
<summary>Issues (2)</summary>

1. **Sumber MIME tidak seragam** — validasi pakai `mimes` (ekstensi), pemetaan tipe pakai `getMimeType()` (konten server), kolom `mime` tersimpan pakai `getClientMimeType()` (klien, bisa dipalsukan). Tidak memblokir karena akses digerbang policy, tapi pertimbangkan menyimpan `getMimeType()` ke kolom `mime` agar `is_image` tidak bisa disetir ekstensi/klien.
2. **Field error kegagalan storage selalu `bukti`** — bila `Storage` gagal untuk file dari `foto_selfie`/`dokumen`, error tetap diikat ke field `bukti`. Flash `error` umum tetap muncul jadi pengguna tahu, tapi penandaan field bisa menyesatkan. Kosmetik.

</details>

<details>
<summary>Details</summary>

### Field campuran `bukti[]` dan pemetaan tipe berbasis MIME

Permintaan "upload foto dan dokumen bersamaan, lebih dari satu" dipenuhi tanpa membongkar alur selfie wajib. `TaskDraftRequest` menambah `bukti => ['nullable','array']` dan `bukti.* => ['file','mimes:jpeg,jpg,png,pdf,doc,docx,xls,xlsx','max:10240']`, sementara `foto_selfie[]` (image, max 5 MB) dan `dokumen[]` (pdf/doc/docx/xls/xlsx, max 10 MB) dipertahankan. `TaskService::simpanLampiran` me-loop ketiga koleksi dan untuk `bukti[]` memanggil `tipeUntuk()`:

```php
private function tipeUntuk(UploadedFile $file): string
{
    return str_starts_with((string) $file->getMimeType(), 'image/')
        ? TaskAttachment::TIPE_FOTO_SELFIE
        : TaskAttachment::TIPE_DOKUMEN;
}
```

Gambar sengaja dipetakan ke `foto_selfie` sehingga ikut menghitung `hasSelfie()` saat submit — ini membuat `KatimkerSubmitTest::test_bukti_gambar_memenuhi_syarat_selfie_saat_submit` lulus dan menjaga syarat selfie wajib tetap benar. Satu record `TaskAttachment` dibuat per file, jadi multi-file didukung tanpa migrasi (skema sudah `hasMany`).

Ada tiga sumber MIME berbeda di jalur ini: validasi `mimes` menilai dari ekstensi, `tipeUntuk()` memakai `getMimeType()` (deteksi isi oleh server), sedangkan kolom `mime` disimpan dari `getClientMimeType()` (dikirim klien). Karena `is_image` di model dihitung dari kolom `mime` tersimpan, flag preview bisa disetir oleh MIME klien yang dipalsukan. Dampaknya terbatas: akses file tetap digerbang `TaskAttachmentPolicy`, dan paling buruk sebuah file salah dirender sebagai `<img>`. Menyimpan `getMimeType()` ke kolom `mime` akan menyeragamkan deteksi (confirmed).

### Notifikasi kegagalan simpan/upload

`simpanSatu` membungkus `store()` + `attachments()->create()` dalam try/catch; `$path === false` atau `Throwable` apa pun diubah menjadi `RuntimeException` berpesan `Gagal menyimpan file "{nama}" ke penyimpanan.`. Controller `store`/`update` menangkap `RuntimeException` dan mengembalikan:

```php
return back()
    ->withErrors(['bukti' => $e->getMessage()])
    ->with('error', 'Tugas gagal disimpan: '.$e->getMessage());
```

Frontend menampilkan tiga lapis umpan balik: `onError` pada submit mengambil error pertama ke Toast, `watch(() => page.props.flash?.error)` (immediate) menampilkan flash dari server, dan `<small v-if="form.errors.bukti">` menandai field. `flash.error` memang di-share lewat `HandleInertiaRequests`, jadi jalur ini tersambung. Form tidak lagi diam saat gagal, dan pesan validasi (`mimes`/`max`/`file`) sudah spesifik Bahasa Indonesia.

Satu catatan kosmetik: kegagalan `Storage` selalu diikat ke field `bukti` lewat `withErrors(['bukti' => ...])`, padahal file yang gagal bisa berasal dari `foto_selfie`/`dokumen`. Flash `error` umum tetap tampil sehingga pengguna tetap diberi tahu, tapi penandaan field spesifik bisa membingungkan bila yang gagal bukan field `bukti` (likely).

### Daftar bukti tersimpan di mode edit

`edit` memuat `->load(['attachments' => fn ($q) => $q->orderBy('id')])` dan `TaskAttachment` meng-`$appends` `url`, `is_image`, `ukuran_label` sehingga setiap attachment terserialisasi lengkap ke props Inertia. `url` menunjuk ke route `bukti.show` (bukan path mentah), `is_image` menentukan render thumbnail vs ikon, dan `ukuran_label` memformat B/KB/MB. `TaskForm.vue` merender daftar: `<a :href="att.url" target="_blank" rel="noopener">` membungkus thumbnail/ikon + nama + ukuran, dan tombol hapus memanggil `useConfirm` lalu `router.delete(route('katimker.tugas.lampiran.destroy', att.id), { preserveScroll, onSuccess, onError })`. Setelah hapus, Inertia memuat ulang props sehingga daftar diperbarui, dengan Toast sukses/gagal. `test_edit_memuat_daftar_bukti_dengan_url` mengassert prop `url` non-null, `is_image` true, dan `ukuran_label` string non-kosong.

### Otorisasi dan konvensi route tetap utuh

Jalur baru tidak melemahkan gerbang akses. `bukti.show` tetap `authorize('view', $attachment)` via `TaskAttachmentPolicy` (pemilik Katimker, Kabid penilai yang dipetakan, atau Admin via `before`); bila `taskReport` null policy menolak (fail-closed). `destroyAttachment` menggerbang `authorize('update', $attachment->taskReport)` sehingga hanya pemilik saat tugas editable yang bisa menghapus lampiran. `edit`/`update`/`destroy` tetap memakai `view`/`update`/`delete` pada `TaskReport` yang mengharuskan kepemilikan (dan `isEditable()`/`isDraf()`). Penamaan route konsisten dengan grup `katimker.` (`tugas.store`, `tugas.update`, `tugas.edit`, `tugas.lampiran.destroy`), dan `deleteDraft` tetap menghapus file dari disk sebelum cascade — alur draft/submit/review tak berubah.

### Cakupan test

Bukti verifikasi coder mencatat `php artisan test --filter=Katimker` lulus (20 passed, 95 assertions) dan `npm run build` sukses. Test baru menutup multi-file campuran (2 attachment, 1 `foto_selfie` + 1 `dokumen`), penolakan tipe tak didukung (`bukti.0`), penolakan ukuran > 10 MB (`bukti.0`), prop edit (`url`/`is_image`/`ukuran_label`), dan gambar `bukti[]` memenuhi `hasSelfie()` saat submit. Test lama (durasi nol, dokumen non-pdf, kepemilikan, hapus draft+lampiran, submit tanpa selfie/mapping, resubmit) dipertahankan.

Not tested: jalur kegagalan `Storage` yang melempar `RuntimeException` dan menghasilkan flash `error` tidak punya test feature (sulit dipalsukan tanpa mock disk, dan di luar skenario inti). Perilaku frontend (Toast, konfirmasi hapus) tidak punya test otomatis — konsisten dengan proyek yang tak punya test komponen Vue.

</details>

<details>
<summary>Peta berkas</summary>

- `app/Http/Requests/TaskDraftRequest.php` — field `bukti[]` + perluasan `dokumen.*` + pesan error Indonesia spesifik.
- `app/Services/TaskService.php` — loop `bukti[]`, `tipeUntuk()` pemetaan MIME, `simpanSatu` try/catch → `RuntimeException`.
- `app/Http/Controllers/Katimker/TaskController.php` — teruskan `bukti` ke service, tangkap `RuntimeException` → flash error, `edit` memuat attachments terurut, `destroyAttachment`.
- `app/Models/TaskAttachment.php` — accessor `url`/`is_image`/`ukuran_label` + `$appends`.
- `resources/js/Pages/Katimker/TaskForm.vue` — FileUpload `bukti[]` multiple, Toast `onError` + watch `flash.error`, daftar lampiran tersimpan (preview/ikon, buka, hapus+konfirmasi).
- `tests/Feature/KatimkerTaskTest.php` — test multi-file campuran, penolakan tipe/ukuran, prop edit.
- `tests/Feature/KatimkerSubmitTest.php` — test gambar `bukti[]` memenuhi syarat selfie saat submit.

Diff lengkap: `git show 6e9c158`.

</details>
