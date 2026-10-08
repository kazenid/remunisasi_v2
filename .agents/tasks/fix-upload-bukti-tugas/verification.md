# Verifikasi — Perbaikan Upload Bukti Tugas (Katimker)

Iterasi: pertama (tidak ada `review.json`). Implementasi mengikuti `plan.md`.

## Ringkasan perubahan

Backend:
- `app/Http/Requests/TaskDraftRequest.php` — tambah field campuran `bukti[]` (mimes: jpeg,jpg,png,pdf,doc,docx,xls,xlsx; max 10 MB) + pesan error Indonesia spesifik; perluas `dokumen.*` ke pdf/doc/docx/xls/xlsx.
- `app/Services/TaskService.php` — loop `bukti[]` dengan pemetaan tipe berbasis MIME (`tipeUntuk`), dan `simpanSatu` dibungkus try/catch yang melempar `RuntimeException` berpesan "Gagal menyimpan file ... ke penyimpanan." bila I/O gagal.
- `app/Http/Controllers/Katimker/TaskController.php` — `store`/`update` meneruskan `bukti` ke service dan menangkap `RuntimeException` → `back()->withErrors(['bukti' => ...])->with('error', 'Tugas gagal disimpan: ...')`. `edit` memuat attachments terurut.
- `app/Models/TaskAttachment.php` — accessor `url` (route `bukti.show`), `is_image`, `ukuran_label`; di-`$appends` agar ikut props Inertia.

Frontend (`resources/js/Pages/Katimker/TaskForm.vue`):
- FileUpload `bukti[]` multiple, accept gambar + dokumen, chip removable, error `<small>` per field.
- `onError` pada submit + watch `flash.error` → Toast error jelas.
- Mode edit: daftar lampiran tersimpan dengan thumbnail gambar / ikon dokumen + nama + ukuran, link buka via `url`, tombol hapus dengan `useConfirm` + toast sukses/gagal.

Tidak ada perubahan skema DB (TaskAttachment sudah hasMany per TaskReport; enum tipe dipertahankan).

## Perintah & hasil

### `php artisan test --filter=Katimker`
LULUS — 20 passed (95 assertions), durasi 26.72s.

Test inti yang ditambahkan (hijau):
- `test_upload_bukti_campuran_membuat_beberapa_attachment` — 2 attachment (1 foto_selfie gambar + 1 dokumen pdf).
- `test_bukti_tipe_tidak_didukung_ditolak` — `assertSessionHasErrors('bukti.0')`.
- `test_bukti_melebihi_ukuran_ditolak` — file > 10 MB ditolak.
- `test_edit_memuat_daftar_bukti_dengan_url` — props `tugas.attachments.0` punya `url`, `is_image`, `ukuran_label`.
- `test_bukti_gambar_memenuhi_syarat_selfie_saat_submit` (KatimkerSubmitTest) — gambar via `bukti[]` memenuhi `hasSelfie()` saat submit.

### `npm run build`
LULUS — Vite build sukses, 1103 modules transformed, tidak ada error kompilasi Vue. `TaskForm` chunk ter-build.
