# Penilaian selfie UI, upload-toast fix, dan rename TUKIMAN — review iterasi 2

Review ini menilai iterasi KEDUA (commit `b7c2ac8`) yang menanggapi `review.json` iterasi 1 (verdict CHANGES_REQUESTED, satu temuan blocking + tiga non-blocking). Enam item fungsional sudah dikonfirmasi benar pada review iterasi 1 dan tidak berubah di iterasi 2; fokus di sini adalah apakah keempat temuan sebelumnya benar-benar ditangani dan apakah ada regresi/temuan baru dari diff iterasi 2. Keempat temuan ditangani: `no_absen` dead reference dihapus dari topbar, toast kontradiktif approve/reject disamakan ke pola flash-only, narasi root-cause item 5 dikoreksi, dan temuan blocking scope-creep diselesaikan lewat dokumentasi per-file + persetujuan pemilik workflow (opsi B, bukan revert) karena perubahan tersebut terbukti load-bearing terhadap backend yang sudah masuk kode.

Watch for:
- **Scope-creep diselesaikan lewat persetujuan, bukan revert (confirmed)** — redesign Login/`auth.user`/topbar/footer/.env.example tetap di commit yang sama; diterima karena load-bearing (`LoginController` memvalidasi field `login`, bukan `email`) dan pemilik workflow menyetujui opsi B. Bukan blocking lagi, tapi diff ini tetap mencampur concern dari task lain.

**Verdict**: APPROVED

## High-level view

Keempat temuan iterasi 1 ditangani dengan benar. Temuan blocking (scope creep) tidak di-revert; investigasi menunjukkan perubahan `email`→`login` di `Login.vue`, rewrite `auth.user`, panel pengguna topbar, dan blok SIKEP di `.env.example` adalah penyelarasan frontend dengan backend yang sudah ada di working tree (dari task `login-redesign-and-employee-photo`, `admin-2fa`, `role-manual-sikep`). `LoginController::store()` memvalidasi `'login' => 'required|string'` dan model `User` memakai `nama`/`username`/`roles()`/`token_pegawai` — merevert UI ke bentuk lama akan merusak autentikasi. Pemilik workflow menyetujui penyelesaian via dokumentasi per-file (opsi B). Ini resolusi yang sah dalam batasan task: user yang authoritative tidak melarang penyelarasan ini, dan merusak login jauh lebih berbahaya daripada diff yang padat.

Temuan 2 (dead reference `no_absen` di `AppTopbar.vue`) dihapus; grep mengonfirmasi `no_absen` hanya tersisa di halaman Admin yang memang mengirim field itu di payload-nya (di luar scope, pre-existing).

Temuan 3 (toast kontradiktif) diselesaikan dengan membuang semua `onSuccess` toast dari `setujui()`/`tolak()`/`ubahJenis()` dan mengandalkan flash server. Jalur gagal (`back()->with('error')`, 302→200) kini hanya memunculkan satu toast error lewat watcher `flash.id`. Karena approve/reject sukses me-redirect ke `kabid.penilaian.index`, watcher `flash.id` yang sama ditambahkan di `ReviewList.vue` agar flash `success` tampil satu kali di halaman tujuan; `ubahJenis()` yang `back()->with('success')` ditangani watcher di `ReviewDetail.vue`. Pemetaan controller→halaman tujuan cocok dengan penempatan watcher.

Temuan 4 (informational) sekadar koreksi narasi di plan/verification; perilaku toggle tema Login tidak berubah dan tetap benar.

Mekanisme flash tetap konsisten lintas tiga halaman (`TaskList`, `ReviewDetail`, `ReviewList`): `flash.id` adalah nonce `Str::uuid()` yang dievaluasi eager tiap request, sehingga pesan identik beruntun selalu memicu watcher; tak ada `immediate` sehingga tak ada toast basi. Tidak ada regresi baru dari diff iterasi 2.

<details>
<summary>Issues (1)</summary>

1. **Diff mencampur concern lintas task (non-blocking, disetujui)** — commit `c063abe` memuat perubahan non-rename (redesign Login, rewrite `auth.user`, panel topbar, footer, blok SIKEP di `.env.example`) yang load-bearing untuk backend yang sudah ada. Sudah didokumentasikan per-file di `verification.md` dan disetujui pemilik workflow (opsi B). Tidak ada aksi lanjutan yang diminta; dicatat agar jelas bahwa diff ini bukan rename murni.

</details>

<details>
<summary>Details</summary>

### Temuan 1 (blocking) — scope creep diselesaikan via dokumentasi + persetujuan

Opsi A reviewer iterasi 1 (revert/pisahkan perubahan out-of-scope) akan merusak autentikasi: `LoginController::store()` memvalidasi `'login' => 'required|string'` lalu memanggil `SikepAuthService::attempt($credentials['login'], ...)`, dan model `User` tidak lagi memakai `name`/`email`. Mengembalikan `Login.vue` ke field `email` atau `auth.user` ke bentuk `{id,name,email}` akan memutus login dan menampilkan payload yang tidak cocok dengan `AppTopbar`/middleware role. Karena merevert mengubah perilaku di luar enam item (dan merusaknya), penyelesaian dieskalasi dan pemilik workflow menyetujui opsi B: tidak revert, dokumentasikan per-file bahwa perubahan ini adalah penyelarasan dengan task sebelumnya. `verification.md` iterasi 2 memuat pemetaan per-file yang eksplisit. Dalam batasan task (pesan user authoritative, larangan mengubah perilaku di luar scope tanpa persetujuan), resolusi ini sah dan bukan lagi blocking.

### Temuan 2 (minor) — dead reference `no_absen`

```diff
-<div class="text-sm text-muted-color truncate">{{ user?.username }}<template v-if="user?.no_absen"> · {{ user.no_absen }}</template></div>
+<div class="text-sm text-muted-color truncate">{{ user?.username }}</div>
```

`auth.user` tidak pernah mengirim `no_absen`, jadi penghapusan tak mengubah tampilan. Grep mengonfirmasi `no_absen` hanya tersisa di `Admin/Pengguna.vue` dan `Admin/ApproverMapping.vue`, yang mengambil data dari payload daftar pegawai (bukan `auth.user`) — di luar scope dan tidak disentuh.

### Temuan 3 (minor) — toast kontradiktif approve/reject disamakan ke flash-only

`onSuccess` toast dibuang dari ketiga aksi:

```diff
-function setujui() {
-    approveForm.post(route('kabid.penilaian.approve', props.tugas.id), {
-        onSuccess: () => toast.add({ severity: 'success', summary: 'Disetujui', ... }),
-    });
-}
+function setujui() {
+    approveForm.post(route('kabid.penilaian.approve', props.tugas.id));
+}
```

Watcher `flash.id` di `ReviewDetail.vue` kini menampilkan `flash.error` DAN `flash.success`. Peta jalur controller→halaman cocok dengan penempatan watcher:

- `approve()`/`reject()` sukses → `redirect()->route('kabid.penilaian.index')->with('success', ...)` → ditangkap watcher baru di `ReviewList.vue`.
- `approve()`/`reject()` gagal → `back()->with('error', ...)` → tetap di `ReviewDetail.vue`, satu toast error.
- `ubahJenis()` sukses → `back()->with('success', 'Jenis tugas diperbarui.')` → ditangkap watcher di `ReviewDetail.vue`.

Hasilnya: gagal = satu toast error, sukses = satu toast sukses, tidak pernah keduanya sekaligus. Watcher di `ReviewList.vue` identik polanya dengan `TaskList.vue` dan `ReviewDetail.vue` (baca `flash.error`/`flash.success`, tanpa `immediate`), jadi tidak ada risiko toast basi saat halaman pertama dibuka.

### Mekanisme flash lintas halaman (konsistensi item 4)

`HandleInertiaRequests::share()` menaruh `'id' => (string) Str::uuid()` sebagai nilai eager (bukan closure), sehingga berubah tiap request dan dievaluasi pada setiap kunjungan termasuk partial reload. Ketiga halaman (`TaskList`, `ReviewDetail`, `ReviewList`) memantau `flash.id` dengan handler yang sama. Dua kegagalan identik beruntun tetap memicu watcher. Tidak ada `onSuccess` toast tersisa di jalur yang bisa bentrok dengan `back()->with('error')`.

### Temuan 4 (informational) — koreksi narasi item 5

Narasi di plan/verification dikoreksi: penyebab panel tema Login tak menutup bukan `animate-scalein`/`animate-fadeout` (kode lama memakai `animate-fadein` yang terdefinisi) melainkan wrapper `hidden` berlapis di sekitar `<AppConfigurator>`; fix nyata = `@next` menunjuk langsung ke root `config-panel`. Perilaku akhir sudah benar sejak iterasi 1 dan tidak berubah; ini murni koreksi dokumentasi.

### Evidence verifikasi

`verification.md` iterasi 2 mencatat `php artisan test` → 160 passed (606 assertions) dan `npm run build` → sukses (4.84s). Sesuai instruksi, suite tidak dijalankan ulang; evidence lengkap dan diterima. Perubahan iterasi 2 murni frontend (hapus `onSuccess`, hapus satu baris template, tambah satu komponen Vue flash-watcher) ditambah koreksi dokumen — tidak ada perubahan backend baru yang bisa menurunkan baseline test. Tidak ada keraguan spesifik yang menuntut spot-check.

</details>

<details>
<summary>File map</summary>

Iterasi 2 (`b7c2ac8`):
- `resources/js/layout/AppTopbar.vue` — hapus dead reference `no_absen` (temuan 2).
- `resources/js/Pages/Kabid/ReviewDetail.vue` — buang `onSuccess` toast dari `setujui`/`tolak`/`ubahJenis`; watcher `flash.id` kini menampilkan error dan success (temuan 3).
- `resources/js/Pages/Kabid/ReviewList.vue` — tambah watcher `flash.id` agar toast sukses approve/reject tampil satu kali di halaman tujuan (temuan 3).
- `.agents/tasks/.../verification.md` + `plan.md` — koreksi narasi root-cause item 5 + dokumentasi load-bearing per-file (temuan 1 & 4).

Iterasi 1 (`c063abe`, dinilai di review sebelumnya, tidak berubah): serializer `ReviewController@show` (+url/is_image/mime), selfie berframe + Dialog di `ReviewDetail.vue`, thumbnail preview `TaskForm.vue`, nonce `flash.id` di `HandleInertiaRequests` + watcher `TaskList.vue`, `FloatingConfigurator.vue` retarget `@next`, rename TUKIMAN di topbar/footer/login/`app.js`/`app.blade.php`/`config/app.php`/`.env.example`, plus perubahan load-bearing login/foto/SIKEP.

Diff iterasi 2: `git show b7c2ac8`. Diff gabungan: `git diff 3b853c9 b7c2ac8`.

</details>
