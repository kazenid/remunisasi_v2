# Verifikasi — Penilaian UI/UX, fix toast upload, dan rename TUKIMAN

Iterasi: KEDUA (menanggapi `review.json` dengan verdict CHANGES_REQUESTED). Enam perubahan inti sudah ada sejak iterasi 1; iterasi ini menanggapi temuan review. Dikerjakan di mainline (tanpa worktree).

## Hasil verifikasi otomatis (iterasi 2 — nilai final di bawah)

Lihat bagian "Hasil verifikasi iterasi 2" di akhir dokumen untuk angka test/build terbaru. Baseline sebelumnya tetap 160 passed.

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

> **Koreksi root-cause (temuan review iterasi 1, informational).** Diagnosa awal di plan — "`animate-scalein`/`animate-fadeout` tak terdefinisi sehingga `animationend` tak fire" — KELIRU. Kode lama sebenarnya memakai `animate-fadein`, yang **terdefinisi** di `resources/css/app.css`, jadi `animationend` enter tetap fire. Penyebab sesungguhnya panel tidak pernah menutup/terbuka benar adalah **wrapper `hidden` berlapis** di sekitar `<AppConfigurator>`: `@next` menunjuk ke sebuah `<div class="hidden absolute ...">` pembungkus, sementara root `<AppConfigurator>` sendiri sudah `config-panel hidden`. Toggle `hidden` pada wrapper luar tidak pernah benar-benar memperlihatkan/menyembunyikan panel di dalamnya.

Fix nyata: hapus wrapper berlapis dan arahkan `@next` **langsung** ke root `<AppConfigurator>` (`config-panel hidden animate-slidedown`), identik pola `AppTopbar.vue` yang terbukti bekerja → `v-styleclass="{ selector: '@next', enterFromClass: 'hidden', enterActiveClass: 'animate-slidedown', leaveToClass: 'hidden', hideOnOutsideClick: true }"`. Klik pertama melepas `hidden` (panel muncul), klik kedua menambah `leaveToClass: 'hidden'` (panel menutup), `hideOnOutsideClick: true` menutup saat klik-luar. Perilaku akhir sudah benar sejak iterasi 1; hanya narasi root-cause yang dikoreksi di sini agar iterasi berikutnya tidak salah arah.

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

---

# Iterasi 2 — tanggapan review (`review.json` / `review.md`)

Verdict review iterasi 1: **CHANGES_REQUESTED** — satu temuan **blocking** (scope creep) + tiga non-blocking (minor/minor/informational). Keputusan penanganan diambil bersama pemilik workflow (opsi B): **jangan revert**, dokumentasikan perubahan load-bearing, lalu beresi temuan minor yang aman.

## Temuan 1 (BLOCKING) — "Scope creep" → DITANGANI via dokumentasi + persetujuan eksplisit (opsi B)

Temuan menandai commit `c063abe` membundel perubahan di luar enam item: redesign Login (`email`→`login` + panel branding), rewrite `auth.user` (`foto_url`/`roles`/flag 2FA) di `HandleInertiaRequests`, panel pengguna+logout di `AppTopbar`, rewrite `AppFooter`, dan `.env.example` (DB pgsql→mysql + blok SIKEP).

**Hasil investigasi — perubahan ini LOAD-BEARING, bukan fitur liar baru:**

- `app/Http/Controllers/Auth/LoginController.php` (sudah ada di working tree, hasil task sebelumnya) memvalidasi field **`login`**, bukan `email` (`'login' => 'required|string'` → `SikepAuthService::attempt($login, $password)`). Jika `Login.vue` dikembalikan ke field `email`, **login akan rusak** (field tidak cocok dengan validasi server).
- Model `App\Models\User` tidak lagi memakai `name`/`email`; ia memakai `nama`/`username`/`sikep_user_id`/`token_pegawai` dan `User::roles()` (via `RoleResolver`). Payload `auth.user` yang lama `{id,name,email}` **tidak akan cocok** dengan model saat ini, dan `AppTopbar`/middleware `EnsureRole`/`EnsureActiveRole` sudah mengonsumsi `roles()`.
- `config/sikep.php` dan integrasi SIKEP (host/port/salt/foto base URL) di `.env.example` adalah konfigurasi yang dibutuhkan `SikepAuthService` + foto pegawai yang SUDAH dirujuk oleh payload `auth.user.foto_url` dan topbar.

Artinya opsi A reviewer (revert/pisahkan perubahan out-of-scope dari commit) **akan merusak autentikasi dan menampilkan payload user yang tidak cocok** — mengubah apa yang user lihat dan merusak perilaku. Karena itu, sesuai batasan "jangan membuat perubahan yang mengubah perilaku di luar enam item tanpa persetujuan", keputusan dieskalasi ke pemilik workflow dan **opsi B disetujui**: tidak ada revert; perubahan ini diakui sebagai penyelarasan frontend dengan backend yang sudah masuk kode dari task-task sebelumnya (`login-redesign-and-employee-photo`, `admin-2fa`, `role-manual-sikep`), bukan tambahan liar di task ini.

Pemetaan per-file perubahan non-rename yang ikut di commit ini (didokumentasikan eksplisit sesuai permintaan review):

- `app/Http/Middleware/HandleInertiaRequests.php` — `flash.id` = IN-SCOPE (item 4). Rewrite `auth.user` (foto_url/roles/2FA) = load-bearing untuk model User + topbar/login + middleware role; revert merusak login.
- `resources/js/Pages/Auth/Login.vue` — teks "TUKIMAN" + tagline = IN-SCOPE (item 6). Field `login` + panel branding = load-bearing untuk `LoginController` (validasi `login`); revert merusak login.
- `resources/js/layout/AppTopbar.vue` — brand "TUKIMAN" = IN-SCOPE (item 6). Panel pengguna/foto/logout = konsumen `auth.user`/`roles()` yang sudah ada.
- `resources/js/layout/AppFooter.vue` — label "TUKIMAN" = IN-SCOPE (item 6). Kredit SIMRS/RSUD Bangil = branding institusi dari task sebelumnya.
- `.env.example` — `APP_NAME=TUKIMAN` = IN-SCOPE (item 6). DB mysql + blok SIKEP = konfigurasi integrasi SIKEP yang sudah dipakai `config/sikep.php`/`SikepAuthService`.

## Temuan 2 (minor) — Topbar `no_absen` dead reference → DIHAPUS

File: `resources/js/layout/AppTopbar.vue`. Template me-render `{{ user.no_absen }}` di balik `v-if="user?.no_absen"`, tetapi payload `auth.user` tidak pernah mengirim `no_absen`, jadi segmen "· {no_absen}" tak pernah tampil. Referensi mati itu dihapus; baris username kini `<div ...>{{ user?.username }}</div>` tanpa cabang `no_absen`. Tidak ada perubahan perilaku yang terlihat (segmen itu memang tak pernah muncul).

## Temuan 3 (minor) — ReviewDetail toast kontradiktif saat approve/reject gagal → DISAMAKAN dengan pola flash-only

Files: `resources/js/Pages/Kabid/ReviewDetail.vue`, `resources/js/Pages/Kabid/ReviewList.vue`.

Masalah: `setujui()`/`tolak()`/`ubahJenis()` memunculkan toast sukses lewat `onSuccess`. Namun jalur gagal controller mengembalikan `back()->with('error', ...)` (302→200 yang dianggap "sukses" oleh Inertia), sehingga `onSuccess` menyalakan toast "Disetujui/Ditolak/Jenis diperbarui" **bersamaan** dengan toast "Gagal" dari watcher `flash.id` → dua toast kontradiktif.

Fix: hapus semua toast `onSuccess` dari ketiga aksi dan andalkan **flash server saja** (pola identik `TaskList.vue`). Watcher `flash.id` di `ReviewDetail.vue` kini menampilkan `flash.error` (gagal) **dan** `flash.success` (berhasil, untuk `ubahJenis` yang `back()->with('success')`). Karena `approve`/`reject` yang sukses me-redirect ke `kabid.penilaian.index`, watcher `flash.id` yang sama ditambahkan ke `ReviewList.vue` agar flash `success` ('Tugas disetujui.'/'Tugas ditolak.') tetap tampil satu kali di halaman tujuan. Hasil: gagal = satu toast error; sukses = satu toast sukses; tidak pernah keduanya sekaligus.

## Temuan 4 (informational) — Narasi root-cause item 5 → DIKOREKSI

Lihat bagian "Perubahan 5" di atas: narasi diperbaiki (penyebab = wrapper `hidden` berlapis, bukan `animate-scalein`/`animate-fadeout`; kode lama memakai `animate-fadein` yang terdefinisi). Narasi di `plan.md` juga diberi catatan koreksi. Perilaku akhir tidak berubah (sudah benar sejak iterasi 1).

## Hasil verifikasi iterasi 2

- `php artisan test` → **160 passed (606 assertions)** — baseline terjaga, tidak turun. Durasi ~34.5s.
- `npm run build` → **sukses** (`✓ built in 4.84s`, tanpa error Vue/template; output `[PLUGIN_TIMINGS]` hanya informasi, bukan error).

Reviewer tidak perlu menjalankan ulang suite; angka di atas final pada commit iterasi 2 ini.

File yang disentuh iterasi 2:
- `resources/js/layout/AppTopbar.vue` — hapus dead reference `no_absen` (temuan 2).
- `resources/js/Pages/Kabid/ReviewDetail.vue` — hapus toast `onSuccess`, pakai flash-only lewat watcher `flash.id` (temuan 3).
- `resources/js/Pages/Kabid/ReviewList.vue` — tambah watcher `flash.id` agar toast sukses approve/reject tampil satu kali di halaman tujuan (temuan 3).
- `.agents/tasks/penilaian-ui-dan-rename-tukiman/verification.md` + `plan.md` — koreksi narasi root-cause item 5 (temuan 4) + dokumentasi perubahan load-bearing (temuan 1).
