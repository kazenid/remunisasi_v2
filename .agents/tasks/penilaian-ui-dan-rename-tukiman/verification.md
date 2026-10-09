# Verifikasi — Penilaian UI/UX, fix toast upload, dan rename TUKIMAN

Iterasi: PERTAMA (tidak ada `review.json`). Keenam perubahan diimplementasikan dari awal mengikuti `plan.md`. Dikerjakan di mainline (tanpa worktree).

## Hasil verifikasi otomatis

- `php artisan test` → **160 passed (606 assertions)** — sama dengan baseline, tidak turun.
- `npm run build` → **sukses** (`✓ built in ~1.3s`, tanpa error Vue/template).

Reviewer tidak perlu menjalankan ulang suite; hasil di atas sudah final pada commit ini.

## Perubahan 1 — Serializer lampiran penilaian

File: `app/Http/Controllers/Kabid/ReviewController.php` (method `show`).

Map `attachments` kini menyertakan `url`, `is_image`, dan `mime` (sebelumnya hanya `id`, `tipe`, `nama_asli`). Nilai dibaca dari atribut turunan model `TaskAttachment` yang sudah di-`append` (`url` = `route('bukti.show', id)`, `is_image`). Kontrak lama tidak diubah (hanya menambah field), sehingga `KabidReviewTest` tetap hijau.

Verifikasi di browser: buka halaman penilaian (`/kabid/penilaian/{id}`) untuk tugas yang punya lampiran — props `attachments` kini memuat `url` yang dipakai `<img>`.

## Perubahan 2 + 3 — Selfie sebagai foto berframe + modal in-app; dokumen tetap link

File: `resources/js/Pages/Kabid/ReviewDetail.vue`.

- Lampiran dipisah via `computed`: `selfies` (`tipe === 'foto_selfie'`) dan `dokumen` (selainnya).
- Selfie dirender sebagai thumbnail berframe Sakai: `<img>` `w-40 h-40 rounded-border object-cover` dengan border `border-surface-200 dark:border-surface-700`. Dibungkus `<button>` agar bisa diklik.
- Klik thumbnail memanggil `bukaSelfie(att)` → set `selfieAktif` + `selfieDialog = true` → PrimeVue `<Dialog modal dismissableMask>` menampilkan gambar ukuran penuh **di dalam aplikasi** (bukan tab baru).
- Dokumen tetap daftar `<a :href="att.url" target="_blank" rel="noopener">` + ikon + `<Tag>` → tetap membuka tab baru.
- Judul berbahasa Indonesia ("Bukti Selfie", "Dokumen"), pesan kosong "Tidak ada bukti." dipertahankan.

Verifikasi di browser (uji light & dark):
- Selfie tampil sebagai foto berframe, bukan link.
- Klik selfie → muncul modal/lightbox in-app; TIDAK membuka tab baru.
- Klik nama dokumen → membuka tab baru.

## Perubahan 3 (create-task) — Thumbnail selfie sebelum submit

File: `resources/js/Pages/Katimker/TaskForm.vue`.

- `selfieBaru` kini menyimpan `{ file, previewUrl }` di mana `previewUrl = URL.createObjectURL(file)`.
- `form.foto_selfie` disinkronkan via `syncSelfieForm()` yang memetakan ke daftar `File` saja — object URL **tidak** ikut dikirim ke server.
- `hapusSelfieBaru(idx)` memanggil `URL.revokeObjectURL` untuk file yang dihapus; `onBeforeUnmount` me-revoke semua sisa — mencegah memory leak.
- Template: `<Chip>` nama file diganti grid thumbnail `<img w-20 h-20 rounded-border object-cover>` dengan tombol hapus kecil (`pi pi-times`, `Button text rounded severity="danger"`).
- Penjaga ukuran (toast "File terlalu besar" > 5 MB selfie / 10 MB bukti) tidak diubah → tetap bekerja.

Verifikasi di browser: buka Tugas Baru → unggah/ambil selfie → thumbnail muncul sebelum submit; klik hapus → thumbnail hilang; submit tetap mengirim file (draf tersimpan, 1 toast sukses).

## Perubahan 4 — Fix toast gagal yang tidak muncul pada kegagalan identik beruntun

Files: `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/Pages/Katimker/TaskList.vue`, `resources/js/Pages/Kabid/ReviewDetail.vue`.

Mekanisme SEBELUM: `TaskList.vue` memantau `page.props.flash?.error` secara langsung. Dua pengajuan gagal beruntun menghasilkan string pesan error yang IDENTIK. Vue `watch` membandingkan nilai lama vs baru; karena string sama persis, watcher dianggap "tidak berubah" dan **tidak fire**, sehingga toast kedua tidak pernah muncul.

Mekanisme SESUDAH: `HandleInertiaRequests::share()` kini menambahkan `'id' => (string) Str::uuid()` pada payload `flash`. Nonce ini dievaluasi ulang pada **setiap** request, jadi nilainya selalu berbeda tiap kunjungan. `TaskList.vue` (dan `ReviewDetail.vue`) memantau `page.props.flash?.id` alih-alih string pesan; di dalam handler ia membaca `flash.error`/`flash.success` lalu menampilkan toast bila ada. Karena `id` selalu berubah, dua error identik beruntun tetap memicu watcher → toast **selalu** muncul.

Invariant yang dijaga:
- Gagal = satu toast error; sukses = satu toast sukses (satu watcher pada `flash.id`, cek error lalu success).
- Tidak memakai `immediate: true` → tidak ada toast "basi" dari kunjungan sebelumnya saat halaman pertama dibuka.
- Tidak menambah toast via `onSuccess` di TaskList → `back()->with('error')` (302→200) tidak memunculkan dua toast.
- Pesan oversize tetap lewat `onError`/handler lokal di TaskForm (tidak terpengaruh).

Catatan test: flash `id` adalah nonce yang tidak dites unit. Suite backend penuh tetap **160 passed**; tidak ada test yang membaca bentuk flash share yang terpengaruh (test membaca `session('errors')`/flash key individual, bukan `flash.id`). Tidak ada pola test Inertia-props existing yang mengassert bentuk `flash`, sehingga tidak ada test baru ditambahkan — perubahan hanya menambah satu key non-breaking.

Verifikasi di browser: di daftar tugas, ajukan tugas tanpa selfie dua kali beruntun → toast error muncul DUA kali; satu kegagalan = satu toast; satu sukses = satu toast sukses.

## Perubahan 5 — Fix theme selector Login (buka lalu klik lagi tidak menutup)

File: `resources/js/Components/FloatingConfigurator.vue`.

Root cause: direktif `v-styleclass` pada tombol palette memakai `enterActiveClass: 'animate-scalein'` dan `leaveActiveClass: 'animate-fadeout'` — dua kelas CSS yang TIDAK terdefinisi. Direktif StyleClass menunggu event `animationend` ketika `*ActiveClass` diset; tanpa animasi CSS, `animationend` tidak pernah fire → state transisi tersangkut → klik ulang tidak menutup.

Fix: samakan dengan pola `AppTopbar.vue` yang terbukti bekerja → `v-styleclass="{ selector: '@next', enterFromClass: 'hidden', enterActiveClass: 'animate-slidedown', leaveToClass: 'hidden', hideOnOutsideClick: true }"`. `animate-slidedown` terdefinisi di `resources/css/app.css`; `leaveToClass: 'hidden'` tanpa `leaveActiveClass` sehingga penutupan tidak menunggu animasi. `hideOnOutsideClick: true` menangani klik-luar.

Verifikasi di browser (WAJIB runtime): di halaman Login, klik ikon palette → panel muncul; klik lagi → panel menutup; klik di luar → menutup. Uji light & dark.

## Perubahan 6 — Rename aplikasi menjadi TUKIMAN

Files:
- `resources/js/layout/AppTopbar.vue`: brand topbar `Remunisasi` → `TUKIMAN`.
- `resources/js/Pages/Auth/Login.vue`: brand panel kiri `Remunisasi` → `TUKIMAN` + tagline "Tugas dan Kinerja Manajemen" (`text-white/80 text-sm`). Heading domain "Sistem Remunerasi Pegawai RSUD Bangil" dipertahankan (deskripsi domain, bukan nama app).
- `resources/js/layout/AppFooter.vue`: tambah label "TUKIMAN" di awal footer.
- `config/app.php`: fallback `env('APP_NAME', 'Laravel')` → `'TUKIMAN'`.
- `.env.example`: `APP_NAME=Laravel` → `APP_NAME=TUKIMAN`.
- `resources/js/app.js`: fallback `VITE_APP_NAME || 'Sakai Laravel'` → `'TUKIMAN'` (judul tab).
- `resources/views/app.blade.php`: fallback `config('app.name', 'Sakai Laravel')` → `'TUKIMAN'`.

TIDAK diubah (di luar scope sesuai plan): `config/sikep.php`, `config/twofactor.php` (issuer), slug-based config (session/cache/redis), nama folder, composer, DB (`remunisasi_v2`), namespace PHP, route name, serta `.env` lokal.

> **Developer wajib mengubah `APP_NAME` di `.env` lokal secara MANUAL** dari `Remunisasi_v2` menjadi `TUKIMAN`. File `.env` TIDAK di-commit, jadi perubahan ini tidak dibawa oleh commit mana pun.

Verifikasi di browser: judul tab, brand topbar, brand login, dan footer menampilkan "TUKIMAN".

## Catatan

- Semua komponen UI diambil dari PrimeVue global (`Dialog`, `Button`, `Tag`, dsb.) — tidak ada komponen baru dibuat dari nol.
- Tidak ada perubahan skema DB; `tipe`/`mime` sudah ada.
- Tidak ada artefak sementara yang perlu dibersihkan.
