# Penilaian selfie UI, upload-toast fix, dan rename TUKIMAN

Commit `c063abe` mengerjakan enam item: render selfie sebagai foto berframe + modal in-app di halaman penilaian, thumbnail preview selfie di create-task, perbaikan toast gagal yang tak muncul pada kegagalan identik beruntun, perbaikan toggle selektor tema di Login, dan rename user-facing ke "TUKIMAN". Keenam item itu sendiri diimplementasikan dengan benar dan konsisten dengan konvensi Sakai/PrimeVue + Tailwind + Inertia, serta memakai Bahasa Indonesia pada teks pengguna. Masalah utama bukan pada keenam item, melainkan pada **blast radius**: commit yang sama membawa serta sejumlah perubahan besar yang tidak terdaftar di antara enam item dan tidak didokumentasikan per-item di `verification.md` — redesign penuh halaman Login (rename field `email`→`login`, panel branding), penulisan ulang payload `auth.user` (foto_url/roles/2FA), panel pengguna + logout di topbar, penulisan ulang footer, dan perubahan `.env.example` (DB pgsql→mysql + blok SIKEP). Perubahan-perubahan ini tampak nyata dan berfungsi (didukung controller/服务 untracked di working tree), tetapi berasal dari task lain dan mencemari diff ini.

Watch for:
- **Scope creep (confirmed)** — commit membundel redesign Login, rewrite `auth.user`, panel pengguna topbar, rewrite footer, dan perubahan DB/SIKEP di `.env.example` yang berada di luar enam item dan tidak didokumentasikan item-per-item. Ini melanggar kriteria "blast radius tetap terkungkung, tanpa refactor tak terkait".
- **Topbar `no_absen` dead reference (confirmed)** — `AppTopbar.vue` me-render `{{ user.no_absen }}` tetapi payload `auth.user` di `HandleInertiaRequests` tidak mengirim `no_absen`; segmen itu tak pernah tampil.
- **ReviewDetail: toast kontradiktif saat approve gagal (likely, pre-existing)** — `onSuccess` menampilkan toast "Disetujui" sementara watcher `flash.id` menampilkan toast "Gagal" pada jalur `back()->with('error')`. Pola ini sudah ada sebelum item 4 (watcher dialihkan dari `flash.error` ke `flash.id`, perilaku error-only dipertahankan), jadi bukan regresi baru, tetapi tetap menyesatkan.

**Verdict**: CHANGES_REQUESTED

## High-level view

Keenam item fungsional benar. Serializer penilaian menambah `url`/`is_image`/`mime` dari atribut model yang sudah ter-`append`; `ReviewDetail.vue` mencabang lampiran pada diskriminator yang tepat (`tipe === 'foto_selfie'`), merender selfie sebagai `<img>` berframe yang membuka PrimeVue `Dialog` in-app, dan mempertahankan dokumen sebagai `<a target="_blank">`. Create-task menyimpan `{ file, previewUrl }`, me-revoke object URL saat hapus/unmount, dan hanya mengirim `File` ke server.

Perbaikan toast (item 4) memakai nonce `flash.id` (`Str::uuid()` per-request) di middleware share, dan watcher di `TaskList.vue` dialihkan untuk memantau `flash.id` alih-alih string pesan. Mekanisme ini benar: dua pesan gagal identik beruntun kini selalu memicu watcher. Invariant single-toast dan larangan `immediate` dipertahankan di TaskList.

Item 5 (toggle tema Login) berperilaku benar di kode akhir — panel menutup saat klik ulang dan klik-luar — tetapi penyebab sebenarnya berbeda dari diagnosa plan: kode lama memakai `animate-fadein` (yang terdefinisi), bukan `animate-scalein`/`animate-fadeout`. Yang benar-benar memperbaiki adalah restrukturisasi `@next` agar menunjuk langsung ke root `AppConfigurator` (`config-panel hidden`), bukan ke wrapper `hidden` berlapis seperti sebelumnya.

Rename TUKIMAN (item 6) tuntas di titik user-facing yang diminta (topbar, footer, login, tab title via `app.js` + `app.blade.php`, `config/app.php`, `.env.example`) tanpa menyentuh folder/composer/DB/namespace/route. Namun commit yang sama memuat banyak perubahan non-rename pada file yang sama (Login, topbar, footer), sehingga sulit memisahkan rename dari fitur lain.

Masalah yang menonjol adalah blast radius: beberapa subsistem besar yang tak termasuk enam item ikut ter-commit. Secara perilaku kode koheren dan evidence verifikasi (160 passed, build sukses) ada, tetapi pencampuran concern membuat diff sulit di-review/revert dan menyalahi batasan scope yang diminta.

<details>
<summary>Issues (4)</summary>

1. **Scope creep tak terdokumentasi** — redesign Login (`email`→`login` + panel branding), rewrite `auth.user` (foto_url/roles/2FA), panel pengguna+logout topbar, rewrite footer, dan perubahan DB/SIKEP di `.env.example` berada di luar enam item. Pisahkan ke commit/task sendiri (mis. `login-redesign-and-employee-photo`) atau dokumentasikan eksplisit dan setujui sebagai bagian scope sebelum merge.
2. **Topbar `no_absen` dead reference** — `AppTopbar.vue` me-render `{{ user.no_absen }}` yang tidak pernah ada di payload `auth.user`. Hapus referensi itu atau tambahkan `no_absen` ke `HandleInertiaRequests::share()`.
3. **ReviewDetail toast kontradiktif saat approve gagal** — `onSuccess` ("Disetujui") menyala bersama toast error dari watcher `flash.id` pada `back()->with('error')`. Samakan dengan pola TaskList (andalkan flash saja, tanpa toast `onSuccess`) agar kegagalan hanya memunculkan satu toast error.
4. **Diagnosa root-cause item 5 keliru di dokumen** — penyebab stuck toggle bukan `animate-scalein`/`animate-fadeout` (kode lama memakai `animate-fadein` yang terdefinisi) melainkan wrapper `hidden` berlapis; perbaiki narasi plan/verification agar tidak menyesatkan iterasi berikutnya. (Perilaku akhir sudah benar.)

</details>

<details>
<summary>Details</summary>

### Serializer penilaian dan diskriminator lampiran (item 1 + 2)

`ReviewController@show` memetakan tiap attachment dengan `id`, `tipe`, `nama_asli`, `url`, `is_image`, `mime`. `url`/`is_image` dibaca dari atribut turunan `TaskAttachment` yang sudah di-`append`, jadi tidak ada query tambahan dan `url` menunjuk ke `route('bukti.show', id)` yang terotorisasi lewat policy. Di `ReviewDetail.vue` pemisahan memakai diskriminator yang benar:

```js
const selfies = computed(() => props.tugas.attachments.filter((a) => a.tipe === 'foto_selfie'));
const dokumen = computed(() => props.tugas.attachments.filter((a) => a.tipe !== 'foto_selfie'));
```

Selfie dirender `<img class="w-40 h-40 rounded-border object-cover border border-surface-200 dark:border-surface-700">` dalam `<button>`; dokumen tetap `<a :href="att.url" target="_blank" rel="noopener">` + ikon + `<Tag>`. Dark mode dan teks Indonesia ("Bukti Selfie", "Dokumen", "Tidak ada bukti.") tertangani.

### Modal in-app untuk selfie (item 3b)

Klik thumbnail memanggil `bukaSelfie(att)` → `selfieDialog = true` → PrimeVue `<Dialog modal dismissableMask>` menampilkan gambar penuh di dalam aplikasi; dokumen tidak diubah dan tetap membuka tab baru. Komponen diambil global, tidak ada import manual.

### Thumbnail preview create-task (item 3)

`selfieBaru` menyimpan `{ file, previewUrl }` dengan `previewUrl = URL.createObjectURL(file)`. `syncSelfieForm()` memetakan ke daftar `File` saja sehingga object URL tidak dikirim ke server. `hapusSelfieBaru` me-`revokeObjectURL` berkas yang dihapus dan `onBeforeUnmount` me-revoke sisa — tidak ada kebocoran. Penjaga ukuran (`toastTerlaluBesar`) tidak disentuh, jadi batas 5 MB/10 MB dan pesan oversize tetap utuh. Fix upload sebelumnya tidak teregresi.

### Mekanisme flash nonce (item 4)

```php
'flash' => [
    'id' => (string) Str::uuid(),
    'success' => fn () => $request->session()->get('success'),
    'error'   => fn () => $request->session()->get('error'),
    ...
],
```

`flash.id` dievaluasi eager tiap request sehingga selalu berubah. `TaskList.vue` memantau `flash.id` dan di handler membaca `flash.error`/`flash.success`:

```js
watch(() => page.props.flash?.id, () => {
    const flash = page.props.flash ?? {};
    if (flash.error)   toast.add({ severity: 'error',   ... });
    if (flash.success) toast.add({ severity: 'success', ... });
});
```

Dua kegagalan identik beruntun kini memicu watcher karena `id` berubah; sukses tetap satu toast; tanpa `immediate` sehingga tidak ada toast basi. Di TaskList invariant single-toast terjaga karena tidak ada toast `onSuccess`.

Catatan untuk `ReviewDetail.vue`: watcher di sana hanya memunculkan toast error dari `flash.id`, tetapi `setujui()`/`tolak()`/`ubahJenis()` tetap memunculkan toast sukses lewat `onSuccess`. Ketika `ReviewService` melempar `ReviewException`, controller mengembalikan `back()->with('error', ...)` — sebuah 302→200 yang dianggap sukses oleh Inertia — sehingga toast "Disetujui/tersimpan" menyala bersama toast "Gagal". Pola ini sudah ada sebelum item 4 (plan menyebut watcher `flash.error` di ReviewDetail), jadi bukan regresi baru, tetapi tetap menyesatkan dan sebaiknya disamakan dengan pola flash-only milik TaskList.

### Toggle selektor tema Login (item 5)

Kode akhir berperilaku benar: `v-styleclass="{ selector: '@next', enterFromClass: 'hidden', enterActiveClass: 'animate-slidedown', leaveToClass: 'hidden', hideOnOutsideClick: true }"` pada tombol palette, dengan `@next` menunjuk langsung ke `<AppConfigurator>` yang root-nya `config-panel hidden animate-slidedown`. Klik pertama melepas `hidden`, klik kedua menambah `leaveToClass: 'hidden'`, dan `hideOnOutsideClick` menutup saat klik luar.

Diagnosa plan tidak tepat: kode lama memakai `animate-fadein` (terdefinisi di `app.css`), bukan `animate-scalein`/`animate-fadeout`. Jadi `animationend` enter DID fire pada kode lama; penyebab stuck sebenarnya adalah struktur lama yang membungkus `<AppConfigurator>` (yang root-nya sudah `hidden`) dalam wrapper `<div class="hidden absolute ...">` berlapis, sehingga toggle `hidden` pada wrapper tidak pernah benar-benar memperlihatkan panel. Perbaikan nyata = menghapus wrapper dan menunjuk `@next` ke `config-panel`. Perilaku akhir benar; hanya narasi root-cause yang perlu dikoreksi agar iterasi berikutnya tidak salah arah.

### Rename TUKIMAN dan blast radius (item 6 + scope)

Teks user-facing terganti: `AppTopbar.vue` brand → "TUKIMAN", `AppFooter.vue` memuat "TUKIMAN", `Login.vue` brand + tagline "Tugas dan Kinerja Manajemen", `app.js` fallback title → "TUKIMAN", `app.blade.php` fallback title → "TUKIMAN", `config/app.php` fallback → "TUKIMAN", `.env.example` `APP_NAME=TUKIMAN`. Folder, composer, DB (`remunisasi_v2`), namespace, dan route name tidak diubah — sesuai batas scope.

Namun diff membawa perubahan besar non-rename pada file yang sama dan file lain, yang tidak termasuk enam item:

- `HandleInertiaRequests` menulis ulang `auth.user` menjadi closure yang mengembalikan `foto_url`, `roles`, `is_katimker/kabid/admin`, dan flag 2FA. Hanya penambahan `flash.id` yang relevan dengan item 4; sisanya milik fitur login/foto pegawai.
- `Login.vue` mengganti field `email`→`login` (didukung `LoginController` yang memvalidasi `login`), menambah panel branding, daftar fitur, dan footer tahun. Ini redesign penuh, bukan sekadar rename.
- `AppTopbar.vue` mengganti logo SVG→img, menambah panel pengguna dengan foto, daftar peran, dan tombol logout.
- `AppFooter.vue` ditulis ulang jadi kredit SIMRS/RSUD Bangil.
- `.env.example` mengubah `DB_CONNECTION` pgsql→mysql, kredensial DB, dan menambah blok konfigurasi SIKEP (host/port/salt/foto base URL).

Semuanya tampak koheren dan berfungsi (controller/service pendukung ada di working tree sebagai untracked), tetapi mereka adalah pekerjaan task lain (mis. direktori `login-redesign-and-employee-photo`, `admin-2fa`, `role-manual-sikep`) yang ikut ter-commit. Mencampur concern ini menyalahi batasan "tanpa refactor tak terkait", menyulitkan review dan revert selektif, dan membuat `verification.md` tidak mewakili isi commit sebenarnya.

Satu bug kecil yang nyata dari rewrite topbar: template me-render `{{ user.no_absen }}` di balik `v-if="user?.no_absen"`, tetapi payload `auth.user` tidak pernah mengirim `no_absen`. Berkat optional chaining ini tidak crash, hanya segmen "· no_absen" yang tak pernah muncul — referensi mati yang sebaiknya dihapus atau datanya ditambahkan ke share payload.

### Evidence verifikasi

`verification.md` mencatat `php artisan test` → 160 passed (606 assertions) dan `npm run build` → sukses. Sesuai instruksi, suite tidak dijalankan ulang; evidence diterima apa adanya. Build sukses juga menyiratkan komponen PrimeVue baru di Login (`Message`, `IconField`, `InputIcon`) ter-resolve lewat auto-import. Tidak ada keraguan spesifik yang menuntut spot-check tambahan.

</details>

<details>
<summary>File map</summary>

- `app/Http/Controllers/Kabid/ReviewController.php` — serializer `show()` menambah `url`/`is_image`/`mime` (file tercatat "new" karena base commit tidak memuatnya; sebagian besar sistem pendukung masih untracked di working tree).
- `app/Http/Middleware/HandleInertiaRequests.php` — tambah `flash.id` (in-scope) + rewrite `auth.user` (out-of-scope).
- `resources/js/Pages/Kabid/ReviewDetail.vue` — selfie berframe + Dialog, dokumen link, watcher `flash.id`.
- `resources/js/Pages/Katimker/TaskForm.vue` — thumbnail preview selfie + revoke object URL.
- `resources/js/Pages/Katimker/TaskList.vue` — watcher dialihkan ke `flash.id`.
- `resources/js/Components/FloatingConfigurator.vue` — retarget `@next` + `animate-slidedown` (toggle tema Login).
- `resources/js/Pages/Auth/Login.vue` — rename TUKIMAN (in-scope) + redesign & field `login` (out-of-scope).
- `resources/js/layout/AppTopbar.vue` — brand TUKIMAN (in-scope) + panel pengguna/logout/foto (out-of-scope); `no_absen` dead reference.
- `resources/js/layout/AppFooter.vue` — rewrite footer (hanya "TUKIMAN" yang in-scope).
- `resources/js/app.js`, `resources/views/app.blade.php`, `config/app.php`, `.env.example` — fallback title/APP_NAME → TUKIMAN; `.env.example` juga ubah DB+SIKEP (out-of-scope).

Diff penuh commit: `git show c063abe` (salinan di `.agents/tasks/penilaian-ui-dan-rename-tukiman/_scoped_diff.txt`).

</details>
