# Design — Role Manual (Lepas Role dari SIKEP)

## Ikhtisar

Perubahan ini memindahkan sumber kebenaran peran Katimker/Kabid dari jabatan
lapangan SIKEP (`ms_jabatan_lapangan_user`) ke kolom lokal `users.role` yang
ditetapkan manual oleh Admin. SIKEP tetap menjadi direktori kepegawaian
read-only (`ms_user`) dan sumber autentikasi (verifikasi password lewat
`SikepAuthService`), tetapi tidak lagi menentukan peran. Setiap user punya
tepat satu peran dari domain tertutup `{admin, katimker, kabid}` dan satu status
`aktif` (boolean). Hanya user lokal yang `aktif = true` dan punya `role` sah
yang boleh login dan memakai aplikasi.

Perubahan menyentuh tujuh lapisan: migrasi skema `users`, `RoleResolver` dan
`User::roles()` (resolusi peran), gerbang login (`LoginController`), menu
Kepegawaian (`UserController` + `Admin/Pengguna.vue`), pemetaan approver
(`ApproverMappingService` + controller + Vue), command `app:set-admin`,
penegakan sesi berjalan (`EnsureRole`), serta factory/test.

Stack dikunci (diverifikasi terhadap `composer.json`/`composer.lock`): **Laravel 13
(v13.18.x; constraint `^13.8`) + Inertia + Vue 3 (Sakai/PrimeVue) + PHP 8.2+**.
Database aplikasi tetap pada koneksi default (MySQL/MariaDB sesuai `.env`); SIKEP
tetap koneksi read-only terpisah (`SikepModel`). Tidak ada pustaka baru yang
diperkenalkan.

Pendekatan yang dipilih untuk penyimpanan peran adalah **opsi 3a: kolom `role`
(string nullable) + `aktif` (boolean) langsung di tabel `users`** — bukan tabel
pivot `role_user` atau pustaka RBAC (mis. spatie/laravel-permission). Alasan:
requirement mengunci 1-user-1-role, konsumen peran saat ini hanya butuh tiga
boolean (`is_katimker/is_kabid/is_admin`), dan menambah pustaka RBAC akan
memperbesar permukaan perubahan tanpa manfaat. Kolom datar paling sederhana,
paling mudah di-query di jalur panas (login/middleware tanpa join), dan konsisten
dengan pola kolom datar yang sudah ada (`is_admin`).

---

## 1. Skema DB & Backfill

### Keputusan kolom (final)

| Kolom   | Tipe             | Null | Default | Domain                         |
| ------- | ---------------- | ---- | ------- | ------------------------------ |
| `role`  | `string(20)`     | ya   | `null`  | `admin` \| `katimker` \| `kabid` \| `null` |
| `aktif` | `boolean`        | tdk  | `true`  | `true` \| `false`              |

- `role` disimpan sebagai string nullable (bukan DB-level `enum`) agar penambahan
  nilai domain masa depan tidak memerlukan `ALTER` enum; domain tertutup
  ditegakkan di lapisan aplikasi (validasi + konstanta). Panjang 20 cukup untuk
  semua nilai sekarang dan ekspansi.
- `null` pada `role` berarti "belum punya peran" → tidak boleh login (gerbang
  login menolak).
- `aktif` default `true`: setiap user yang baru ditarik Admin langsung aktif.

### Migrasi baru (JANGAN ubah migrasi lama)

File: `database/migrations/2026_10_08_100001_add_role_and_aktif_to_users_table.php`
(timestamp harus > migrasi `users` dan 2FA yang sudah ada; sesuaikan dengan
tanggal pembuatan sebenarnya, tetap setelah `2026_10_07_100002`).

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('role', 20)->nullable()->after('is_admin');
        $table->boolean('aktif')->default(true)->after('role');
    });

    // Backfill idempotent — aman dijalankan pada data produksi.
    // 1) Admin lama -> role=admin, aktif=true (jaga invarian role=admin <=> is_admin=true).
    DB::table('users')->where('is_admin', true)->update([
        'role' => 'admin',
        'aktif' => true,
    ]);

    // 2) Non-admin lama -> role=null, aktif=false.
    //    Keputusan: user non-admin lama TIDAK bisa dipetakan otomatis ke
    //    katimker/kabid (sumber SIKEP sudah dinyatakan salah), jadi mereka
    //    DINONAKTIFKAN sampai Admin menarik ulang & menetapkan peran. Ini
    //    sesuai keputusan pemilik "SIKEP tidak lagi menentukan role" dan
    //    mencegah akses tak sengaja. (Requirement 1.4, Asumsi A3.)
    DB::table('users')->where('is_admin', false)->update([
        'role' => null,
        'aktif' => false,
    ]);
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn(['role', 'aktif']); // is_admin TIDAK disentuh
    });
}
```

Catatan: `down()` hanya menghapus `role` dan `aktif`. `is_admin` tetap utuh,
memenuhi Requirement 1.6.

### Keputusan backfill (eksplisit)

- `is_admin = true` → `role = 'admin'`, `aktif = true`.
- `is_admin = false` → `role = null`, `aktif = false`.

Konsekuensi yang DISENGAJA: user non-admin yang sebelumnya bisa login lewat
peran SIKEP akan terkunci setelah migrasi sampai Admin menariknya di menu
Kepegawaian dan menetapkan peran. Ini aman-secara-default dan sesuai keputusan
melepas role dari SIKEP.

### Prosedur deploy tunggal (WAJIB, anti-lockout total)

Pada DB produksi yang belum punya admin (`is_admin=true` berjumlah nol), backfill
akan menonaktifkan SEMUA user → nol user bisa login. Untuk mencegah lockout total,
jalankan urutan berikut PERSIS (satu-satunya urutan yang didukung; jangan dicampur
dengan command versi lama):

1. **Deploy kode** (termasuk `SetAdmin` command versi baru yang memakai `setRole()`).
2. **Jalankan migrasi**: `php artisan migrate` (menambah kolom + backfill).
3. **Set admin pertama**: `php artisan app:set-admin <login>`. Command versi baru
   men-set `role='admin'`, `is_admin=true`, `aktif=true` (lihat §5), sehingga admin
   bootstrap tetap bisa login meski backfill menonaktifkan semua non-admin.
4. **Admin login** lalu menarik user lain lewat menu Kepegawaian.

Alasan urutan ini: langkah 3 dijalankan SETELAH migrasi dan memakai `setRole()`
yang menulis kolom baru secara eksplisit, jadi tidak tergantung pada nilai
`is_admin` saat backfill. Menjalankan `app:set-admin` SEBELUM migrasi TIDAK
didukung karena command lama hanya menulis `is_admin` dan interaksinya dengan
backfill bergantung versi; prosedur di atas menghindari ambiguitas tersebut.

Terdapat feature test khusus (lihat §9) yang mensimulasikan backfill pada data
hanya-non-admin lalu memverifikasi bahwa admin yang di-set via command bisa login.

### Invarian `role = 'admin'` ⇔ `is_admin = true`

Sumber kebenaran efektif adalah `role`. `is_admin` dipertahankan sebagai kolom
turunan yang WAJIB disinkronkan setiap kali `role` berubah. Penegakan sinkronisasi
dilakukan di SATU tempat terpusat: method `User::setRole(?string $role)` (lihat
§2) yang dipanggil oleh command, controller, dan seeding/test.

**Aturan jalur-tulis (ditegakkan di seluruh basis kode):** tidak ada kode yang
boleh menulis `role` MAUPUN `is_admin` secara langsung, kecuali melalui
`User::setRole()`. Dua pengecualian yang disengaja dan terdokumentasi: (a)
backfill migrasi (menulis kedua kolom secara eksplisit dalam satu `update`), dan
(b) `UserFactory` state (menulis `role`+`is_admin` berpasangan agar invarian
tetap terjaga, lihat §9). Konsekuensi langsung: method lama
`UserController::setAdmin()` yang menulis `$local->is_admin = ...` secara langsung
WAJIB DIHAPUS (bukan sekadar route-nya), dan `SetAdmin` command WAJIB memakai
`setRole()` (lihat §5). Dengan ini invarian dua arah Requirement 1.5
("`role='admin'` ⇔ `is_admin=true`, dan sebaliknya") dijamin karena satu-satunya
jalur tulis `is_admin` yang hidup adalah `setRole()`.

---

## 2. Model `User` — kolom, cast, helper sinkronisasi

File: `app/Models/User.php`.

Perubahan:

1. Tambah `role` dan `aktif` ke atribut `#[Fillable([...])]`.
2. Tambah cast `'aktif' => 'boolean'` di `casts()`. (`role` string biasa, tanpa
   cast.)
3. Tambah konstanta domain peran dan helper sinkron:

```php
public const ROLE_ADMIN = 'admin';
public const ROLE_KATIMKER = 'katimker';
public const ROLE_KABID = 'kabid';

/** @var list<string> */
public const ROLES = [self::ROLE_ADMIN, self::ROLE_KATIMKER, self::ROLE_KABID];

/**
 * Set peran sekaligus menjaga invarian role=admin <=> is_admin=true.
 * TIDAK memanggil save(); pemanggil bertanggung jawab menyimpan.
 */
public function setRole(?string $role): void
{
    $this->role = $role;
    $this->is_admin = ($role === self::ROLE_ADMIN);
}
```

4. `syncFromSikep()` **TIDAK diubah**: tetap hanya menulis field identitas
   (`username`, `nama`, `no_absen`, `token_pegawai`, `last_sync_at`). Ia JANGAN
   menyentuh `role`/`aktif`/`is_admin` agar `updateOrCreate` untuk user yang
   sudah ada tidak diam-diam memberi akses atau mereset peran. Penetapan peran
   selalu lewat `setRole()` di pemanggil (command/controller). Untuk record baru,
   kolom `role` default `null` dan `aktif` default `true` dari skema; pemanggil
   WAJIB memanggil `setRole()` lalu `save()` agar user punya peran.

   Catatan konsistensi (bukan perubahan requirement): record baru hasil
   `syncFromSikep()` yang BELUM dilengkapi `setRole()` akan berstatus
   `aktif=true` + `role=null`. Ini tidak memberi akses apa pun karena gerbang
   login (§4) dan `RoleResolver` (§3) sama-sama menolak `role=null`. Pada jalur
   normal hal ini tidak pernah terwujud: satu-satunya pemanggil `syncFromSikep()`
   yang membuat record baru adalah `UserController::tarik()` (§6) dan `SetAdmin`
   (§5), dan keduanya SELALU memanggil `setRole()` + men-set `aktif` dalam
   transaksi penyimpanan yang sama. Default skema `aktif=true` dipertahankan
   sesuai Requirement 1.1; tidak diubah ke `false`.

5. `roles()` tetap mendelegasikan ke `RoleResolver::resolve($this->sikep_user_id)`
   (lihat §3 — kontrak nilai tidak berubah, hanya sumbernya).

6. `requiresTwoFactor()` tetap `return $this->roles()['is_admin'] === true;`.
   Karena `RoleResolver` kini membaca `role` lokal, hasilnya identik dengan
   `role === 'admin'`, tetapi kontrak method tidak berubah.

Validasi tipe/limit kolom (lapisan aplikasi): `role` divalidasi terhadap
`User::ROLES` di setiap titik tulis eksternal (controller/command). `aktif`
boolean. Pelanggaran domain `role` di controller menghasilkan `ValidationException`
(pesan Indonesia); di command menghasilkan exit `FAILURE` dengan pesan error.

---

## 3. `RoleResolver` — resolusi dari kolom lokal

File: `app/Services/Sikep/RoleResolver.php`.

Keputusan: `resolve()` TIDAK lagi menanyakan `SikepJabatanLapanganUser`. Ia
membaca `role` dan `aktif` dari tabel `users` lokal. Bentuk keluaran tetap
`{roles, is_katimker, is_kabid, is_admin}` sehingga seluruh konsumen
(`HandleInertiaRequests`, `DashboardController`, `EnsureRole`,
`UserController`, `ApproverMappingService` tidak lagi, lihat §6, policies) tetap
bekerja tanpa perubahan kontrak nilai (Requirement 8.3).

Keputusan penting — **penegakan `aktif` di dalam RoleResolver**: bila user tidak
ditemukan, `aktif = false`, atau `role = null`, `resolve()` mengembalikan
peran kosong (semua boolean `false`, `roles = []`). Dengan begitu, user
nonaktif/null otomatis kehilangan seluruh peran di SEMUA konsumen tanpa harus
menambah cek di tiap tempat. Ini menjadi tulang punggung penegakan sesi berjalan
(§7) karena `EnsureRole` memanggil `roles()`.

Degradasi anggun: karena sumber data kini tabel lokal (koneksi default aplikasi),
tidak ada lagi query SIKEP di jalur panas (memenuhi NFR5). `try/catch QueryException`
tetap dipertahankan sebagai pertahanan berlapis — bila query lokal gagal karena
alasan tak terduga, kembalikan peran kosong dan `report($e)` (fail-closed, lebih
aman daripada fail-open).

Implementasi baru:

```php
public function resolve(int $sikepUserId): array
{
    $empty = ['roles' => [], 'is_katimker' => false, 'is_kabid' => false, 'is_admin' => false];

    try {
        $user = User::query()
            ->where('sikep_user_id', $sikepUserId)
            ->first(['role', 'aktif']);
    } catch (QueryException $e) {
        report($e);
        return $empty; // fail-closed
    }

    if ($user === null || $user->aktif === false || $user->role === null) {
        return $empty;
    }

    $role = $user->role;

    return [
        'roles' => [$role],
        'is_katimker' => $role === User::ROLE_KATIMKER,
        'is_kabid' => $role === User::ROLE_KABID,
        'is_admin' => $role === User::ROLE_ADMIN,
    ];
}
```

Dampak ke pemanggil (daftar lengkap konsumen `roles()` / `RoleResolver::resolve()`
nyata, dikonfirmasi di kode — Requirement 8.3):

- `EnsureActiveRole` + `EnsureRole` (§7): user nonaktif/null → `roles = []` →
  tidak cocok peran apa pun. Lihat §7 untuk logout+redirect.
- `User::requiresTwoFactor()` → dipakai `EnsureTwoFactor`: hanya `true` bila
  `role === 'admin'` aktif.
- `DashboardController::index()`: me-resolve `roles` untuk menentukan blok data
  yang ditampilkan; kini mencerminkan role lokal. Juga dicegat `EnsureActiveRole`
  (§7) sehingga user `roles=[]` di-logout sebelum render.
- `IndividualTargetController::store()`: memakai `$user->roles()` (`is_admin`,
  `is_kabid`). Kontrak nilai tidak berubah, jadi aman tanpa modifikasi.
- `UserController::daftarUserLokal()`/`cariPegawai()`: TIDAK lagi memakai
  `roles->resolve()` untuk menyusun props; menampilkan kolom `role` dan `aktif`
  apa adanya (lihat §6). Dependensi `RoleResolver` di controller ini dihapus.
- Policies (`ApproverMappingPolicy`, `TaskAttachmentPolicy` via `hasRole`):
  memakai bentuk `{roles,is_*}`; tetap bekerja tanpa perubahan.
- `HandleInertiaRequests`: props `auth.user.is_katimker/is_kabid/is_admin/roles`
  tetap akurat berdasarkan role lokal.

`SikepUser::JABATAN_KEPALA_TIM`, `JABATAN_KABID`, `isKatimker()`, `isKabid()`
TIDAK dihapus (Requirement 8.2 / Out of Scope). Mereka tidak lagi dirujuk oleh
`RoleResolver`/`ApproverMappingService` tetapi tetap tersedia untuk laporan
masa depan.

---

## 4. Gerbang Login — `LoginController::store()`

File: `app/Http/Controllers/Auth/LoginController.php`.

### Invarian Urutan (dipertahankan dan diperkuat)

Urutan langkah dalam `store()` wajib persis sebagai berikut; cabang 2FA WAJIB
memakai `$roles['is_admin']` dari hasil resolve tunggal, BUKAN
`$user->requiresTwoFactor()` (yang me-resolve ulang dan bisa menyimpang):

1. Validasi field `login` + `password` (sudah ada).
2. `SikepAuthService::attempt()`. Bila `QueryException` → tolak dengan pesan
   "Integrasi SIKEP tidak tersedia saat ini. Coba lagi nanti." (Requirement 2.7).
   Bila `null` → tolak dengan pesan generik "Kredensial yang diberikan tidak
   cocok." (Requirement 2.1).
3. **Cek record lokal eksplisit** (BARU) — INI SATU-SATUNYA penentu ketiga pesan
   2.2/2.3/2.4. Ambil record lokal dengan kolom yang dibutuhkan secara eksplisit:
   `$local = User::where('sikep_user_id', $sikepUser->id)->first(['id','sikep_user_id','role','aktif']);`
   (muat `role` dan `aktif` agar tak ada lazy-load tambahan). Evaluasi berurutan:
   - Bila `$local === null` → tolak: "Akun Anda belum terdaftar/diaktifkan di
     aplikasi. Hubungi administrator." (Requirement 2.2). JANGAN panggil
     `syncFromSikep()` (agar tidak membuat record otomatis yang memberi akses;
     penarikan hanya oleh Admin). Konsekuensi test: `assertDatabaseCount('users', 0)`
     tetap berlaku.
   - Bila `$local->aktif === false` → tolak: "Akun Anda dinonaktifkan. Hubungi
     administrator." (Requirement 2.3).
   - Bila `$local->role === null` → tolak: "Akun Anda belum memiliki peran.
     Hubungi administrator." (Requirement 2.4).

   **Aturan tegas (menutup ambiguitas dua sumber pesan):** ketiga kondisi di atas
   WAJIB dibedakan HANYA oleh cek eksplisit langkah 3 ini. `RoleResolver::resolve()`
   (§3) yang juga fail-closed untuk ketiga kondisi tersebut TIDAK boleh dipakai
   untuk menentukan/membedakan pesan penolakan — jika hanya mengandalkan resolver,
   ketiganya akan menghasilkan satu pesan generik dan melanggar Requirement
   2.2/2.3/2.4. Jangan menyederhanakan langkah 3 menjadi cek `$roles['roles'] === []`.
4. **Resolve role lokal (HANYA untuk cabang 2FA)**:
   `$roles = $this->roleResolver->resolve($sikepUser->id);`. Setelah lolos langkah
   3, hasilnya dijamin non-kosong. Hasil resolver dipakai HANYA untuk membaca
   `$roles['is_admin']` pada cabang 2FA (langkah 6); ia TIDAK dipakai untuk
   menentukan pesan penolakan apa pun. Defensif (jaring pengaman, bukan jalur
   pesan): bila entah bagaimana `$roles['roles'] === []` padahal langkah 3 lolos,
   tolak dengan pesan 2.4 (fail-closed). Role efektif berasal dari kolom `role`
   lokal, bukan jabatan SIKEP (Requirement 2.6).
5. **Update identitas** (opsional, aman): panggil `User::syncFromSikep($sikepUser)`
   hanya untuk menyegarkan `nama/username/no_absen/token_pegawai/last_sync_at`.
   Karena record sudah dipastikan ada di langkah 3 dan `syncFromSikep` tidak
   menyentuh role/aktif, ini aman dan mempertahankan perilaku "catat last sync".
   Gunakan objek yang dikembalikan sebagai `$user`.
6. **Cabang 2FA** (dipertahankan): `$isAdmin = $roles['is_admin'];`
   bila `$isAdmin && $user->hasConfirmedTwoFactor()` → simpan pending-2FA,
   redirect `two-factor.challenge`. Selain itu `Auth::login()` + regenerate +
   redirect `dashboard`. (Requirement 2.5, 2.8.)

### Celah pending-2FA — guard fail-closed di `TwoFactorController::verifyChallenge()` (BARU)

File: `app/Http/Controllers/Auth/TwoFactorController.php`.

Masalah yang ditutup (Temuan review 1, diverifikasi langsung di kode): alur
pending-2FA admin membuka jendela yang TIDAK dicakup `EnsureActiveRole`. Urutan
nyata:

1. Admin lolos gerbang login (`store()`) saat `aktif=true`, `role=admin` → sesi
   menyimpan `2fa.pending_user_id`, redirect `two-factor.challenge`. Pada titik
   ini user BELUM `Auth::login()`.
2. Route `two-factor.challenge` dan `two-factor.challenge.verify` berada DI LUAR
   grup `['auth','active-role','two-factor']` (diverifikasi di `routes/web.php`
   baris 34–38, didefinisikan SEBELUM grup auth). Jadi `EnsureActiveRole` TIDAK
   pernah berjalan untuk kedua route ini.
3. `verifyChallenge()` saat ini (diverifikasi) hanya memeriksa `two_factor_secret`
   dan `hasConfirmedTwoFactor()` sebelum `Auth::login($user, ...)`. TIDAK ada cek
   `aktif`/`role`.

Konsekuensi: bila admin dinonaktifkan (atau `role` di-set `null`) oleh admin lain
SETELAH langkah 1 tetapi SEBELUM langkah 3, user tetap bisa menyelesaikan challenge
dan `Auth::login()` berhasil; baru pada request terautentikasi BERIKUTNYA
`EnsureActiveRole` me-logout dia. Ini kelas risiko "user nonaktif akses lewat sesi
lama" yang WAJIB diblokir.

Keputusan (final): tambahkan guard fail-closed di `verifyChallenge()` SEBELUM
`Auth::login()`, memakai sumber kebenaran yang sama dengan middleware
(`RoleResolver::resolve()` yang kini membaca `role`/`aktif` lokal, §3) dan pesan
tunggal `EnsureActiveRole::MESSAGE` (§7) agar teks tak pernah menyimpang. Guard
ditempatkan SETELAH guard integritas 2FA yang sudah ada (secret null /
`hasConfirmedTwoFactor()`) dan SETELAH verifikasi kode sukses (`$ok === true`),
tetapi SEBELUM `Auth::login()` — sehingga user yang dinonaktifkan di tengah tidak
pernah mendapat sesi login.

Alasan menaruh guard SETELAH verifikasi kode (bukan di awal method): urutan ini
tidak membocorkan informasi status akun kepada penebak kode yang gagal (user yang
tidak tahu kode tetap mendapat error "Kode verifikasi tidak valid." lebih dulu),
dan tetap fail-closed karena kode benar pun tidak menghasilkan login bila
`roles=[]`.

Dependency: inject `RoleResolver` lewat konstruktor controller
(`public function __construct(private readonly TwoFactorService $service,
private readonly \App\Services\Sikep\RoleResolver $roleResolver) {}`),
mengikuti pola injeksi yang sudah dipakai `LoginController`. Resolver di-mock
pada test tetap valid karena kontrak nilainya tidak berubah.

```php
// verifyChallenge(): setelah $ok === true (kode valid), SEBELUM Auth::login():
$roles = $this->roleResolver->resolve($user->sikep_user_id);
if ($roles['roles'] === []) {
    // Dinonaktifkan / role=null di tengah pending-2FA → fail-closed, tolak login.
    $request->session()->forget(['2fa.pending_user_id', '2fa.remember']);

    return redirect()->route('login')->withErrors([
        'login' => \App\Http\Middleware\EnsureActiveRole::MESSAGE,
    ]);
}

Auth::login($user, (bool) $request->session()->get('2fa.remember'));
// ... (lanjutan yang sudah ada: forget + regenerate + redirect intended)
```

Error handling guard ini: kondisi `roles=[]` → tidak-recoverable tanpa tindakan
admin, tidak membuat sesi, tidak dilog sebagai error (bukan kegagalan sistem);
pending-2FA dibersihkan agar user kembali ke titik awal. Bila `RoleResolver`
sendiri melempar (sudah fail-closed di §3 → `roles=[]`), hasilnya identik:
login ditolak. Feature test khusus ditambahkan di §9.

### Pesan error (final, Bahasa Indonesia)

| Kondisi                              | Field | Pesan                                                                 |
| ------------------------------------ | ----- | --------------------------------------------------------------------- |
| SIKEP down (QueryException)          | login | Integrasi SIKEP tidak tersedia saat ini. Coba lagi nanti.             |
| Password salah / user SIKEP tak ada  | login | Kredensial yang diberikan tidak cocok.                                |
| Tak ada record lokal                 | login | Akun Anda belum terdaftar/diaktifkan di aplikasi. Hubungi administrator. |
| `aktif = false`                      | login | Akun Anda dinonaktifkan. Hubungi administrator.                       |
| `role = null`                        | login | Akun Anda belum memiliki peran. Hubungi administrator.                |

Semua ditolak via `ValidationException::withMessages(['login' => ...])`, tidak
membuat sesi. Perhatikan: pesan "belum memiliki peran" lama berbunyi
"...peran (Katimker/Kabid/Admin)..."; teks baru disederhanakan sesuai Requirement
2.4. Test yang hanya mengecek `assertSessionHasErrors('login')` tetap lolos; test
yang mengecek string spesifik perlu diselaraskan (lihat §9).

Error handling tiap operasi login:
- `attempt()` lempar `QueryException` → recoverable bagi user (coba lagi),
  dillog via `report()`, tidak membuat sesi.
- `attempt()` return `null` → tidak-recoverable pada percobaan ini, tidak dilog
  (bukan error sistem), tidak membuat sesi.
- Penolakan record lokal (3 cabang) → tidak-recoverable tanpa tindakan admin,
  tidak dilog sebagai error, tidak membuat sesi.

---

## 5. Command `app:set-admin`

File: `app/Console/Commands/SetAdmin.php`. Signature tetap
`app:set-admin {login} {--off}`.

Keputusan `--off` (final, didokumentasikan): `--off` hanya **mencabut status
admin** — tidak menonaktifkan akun. Resultan:

- Tanpa `--off`: `setRole('admin')` (→ `role='admin'`, `is_admin=true`) dan
  `aktif = true`. Sync dari SIKEP bila belum ada record. (Requirement 6.2.)
- Dengan `--off`: `setRole(null)` (→ `role=null`, `is_admin=false`). `aktif`
  TIDAK diubah (tetap seperti sebelumnya). (Requirement 6.3.)

Alasan `--off` → `role=null` dan BUKAN menonaktifkan: command ini bootstrap
tingkat operator untuk peran admin, bukan alat menonaktifkan akun. Menyetel
`role=null` konsisten dengan invarian (admin dicabut → bukan admin lagi, dan
karena 1-user-1-role tidak ada peran lain yang otomatis menggantikan). Membiarkan
`aktif` apa adanya menghindari efek samping tak terduga (operator yang hanya ingin
mencabut admin tidak sekaligus memutus akses bila akun itu mestinya tetap aktif
dengan peran lain yang nanti ditetapkan Admin lewat UI). Konsekuensi: setelah
`--off`, user tidak bisa login (role=null) sampai Admin menetapkan peran baru;
ini sesuai gerbang login 2.4 dan merupakan perilaku aman.

Guard: command TIDAK menambahkan anti-lockout global (itu domain UI Admin, §4
Kepegawaian). Namun karena command bersifat operator CLI (bootstrap), ini
dapat diterima; dokumentasikan di deskripsi command bahwa `--off` mencabut admin
dan dapat mengakibatkan nol admin bila dipakai sembarangan.

Implementasi inti:

```php
$user = User::syncFromSikep($sikep);

if ($this->option('off')) {
    $user->setRole(null);           // role=null, is_admin=false; aktif tak diubah
} else {
    $user->setRole(User::ROLE_ADMIN); // role=admin, is_admin=true
    $user->aktif = true;
}
$user->save();

$status = $this->option('off') ? 'DICABUT dari admin' : 'DIJADIKAN admin';
```

Pesan hasil tetap Bahasa Indonesia seperti sekarang (Requirement 6.4).

---

## 6. Menu Kepegawaian — `UserController` + `Admin/Pengguna.vue`

File: `app/Http/Controllers/Admin/UserController.php`,
`resources/js/Pages/Admin/Pengguna.vue`, `routes/web.php`.

### Routes (grup `admin`, prefix `admin`, nama `admin.`)

Pertahankan route yang ada dan ganti/ tambah aksi:

```php
Route::get('/pengguna', [UserController::class, 'index'])->name('pengguna.index');
Route::post('/pengguna/tarik', [UserController::class, 'tarik'])->name('pengguna.tarik');   // tarik + tetapkan role
Route::post('/pengguna/{user}/role', [UserController::class, 'ubahRole'])->name('pengguna.role'); // ubah role
Route::post('/pengguna/{user}/aktif', [UserController::class, 'toggleAktif'])->name('pengguna.aktif'); // toggle aktif
```

`{user}` adalah binding `App\Models\User` (primary key lokal `id`). Route lama
`pengguna.set-admin` DIHAPUS, DAN method `UserController::setAdmin()` juga DIHAPUS
dari controller (bukan hanya route-nya) — lihat §1 "Aturan jalur-tulis": method
itu menulis `is_admin` langsung sehingga melanggar invarian bila tersisa.
Penetapan admin kini lewat `ubahRole` dengan `role=admin`. (SetAdmin command tetap
untuk bootstrap CLI dan sudah memakai `setRole()`.)

Dependensi controller: `RoleResolver` dihapus dari konstruktor `UserController`
karena props tidak lagi memakai hasil resolve (lihat §3). `AuditLogger` tetap.

**Catatan nama aksi audit (menutup Temuan review 3 / NIT).** Kode lama memakai
aksi audit `'role.update'` (satu-satunya pemakai yang hidup: method
`UserController::setAdmin()` yang DIHAPUS). Desain memakai nama baru yang lebih
spesifik: `'pengguna.tarik'`, `'pengguna.role'`, `'pengguna.aktif'`. Diverifikasi
via grep `'role.update'` di seluruh `app/`, `resources/js/`, dan `tests/`:
satu-satunya kecocokan selain `setAdmin()` adalah assertion di
`AdminManagementTest.php` (sudah ditangani §9). TIDAK ada `AuditLogController`/
filter UI yang meng-hardcode string `'role.update'`. SEBELUM implementasi,
jalankan ulang grep `'role.update'` untuk memastikan tidak ada pemakai baru yang
muncul; perbarui setiap kecocokan ke nama aksi baru yang sesuai.

### `index()` — props Inertia

```jsonc
{
  "users": [
    {
      "id": 12,                       // PK lokal (untuk route binding)
      "sikep_user_id": 101,
      "nama": "dr PENGGUNA UJI",
      "username": "uji101",
      "no_absen": 900101,
      "role": "katimker",            // null|admin|katimker|kabid (nilai kolom lokal)
      "aktif": true,
      "last_sync_at": "2026-10-08 10:00:00"
    }
  ],
  "hasilCari": [
    {
      "sikep_user_id": 205,
      "nama": "NAMA PEGAWAI",
      "username": "pegawai205",
      "no_absen": 900205,
      "sudah_ditarik": false,         // true bila sudah ada record users lokal
      "role": null                    // role lokal bila sudah ditarik, else null
    }
  ],
  "q": "cari",
  "roleOptions": [                    // untuk dropdown penetapan role
    { "label": "Admin", "value": "admin" },
    { "label": "Katimker", "value": "katimker" },
    { "label": "Kabid", "value": "kabid" }
  ]
}
```

`daftarUserLokal()` kini menampilkan kolom `role` dan `aktif` apa adanya (tidak
lagi hanya `is_admin`/hasil resolve SIKEP). `cariPegawai()` tetap minimal 3
karakter (Requirement 3.1). `sudah_ditarik=true` bila sudah ada record `users`
lokal untuk `sikep_user_id` tersebut (`User::whereIn('sikep_user_id', ...)->pluck`);
`role` diisi dari record lokal itu bila ada, else `null`.

**Aturan UI untuk pegawai `sudah_ditarik` (keputusan final):** di kartu hasil
cari, tombol "Tarik" di-**disable** dengan tooltip "Sudah ditarik — kelola di
daftar user lokal". Admin tidak melakukan re-tarik dari kartu cari; pengelolaan
role/aktif untuk user yang sudah ada dilakukan di kartu daftar user lokal (via
`ubahRole`/`toggleAktif`). Alasan: menghindari dua jalur tulis role yang
tumpang-tindih (tarik vs ubahRole) dan menjaga `tarik()` murni untuk pembuatan
record baru. Endpoint `tarik()` tetap defensif menolak bila record sudah ada
(lihat validasi `tarik()`).

### `tarik(Request $request)` — Requirement 3.2–3.5

Validasi:

```php
$data = $request->validate([
    'sikep_user_id' => 'required|integer',
    'role' => ['required', Rule::in(User::ROLES)],
]);
```

Alur:
1. `authorize('manage', ApproverMapping::class)` (admin-only, konsisten dgn kode
   saat ini).
2. Guard "sudah ditarik" (selaras aturan UI, pertahanan server): bila
   `User::where('sikep_user_id', $data['sikep_user_id'])->exists()` → tolak dengan
   `ValidationException` `['sikep_user_id' => 'Pegawai sudah ditarik. Kelola peran/status di daftar user lokal.']`.
   `tarik()` murni untuk pembuatan record baru.
3. Cari `SikepUser` aktif by `id`. Bila tidak ada → `ValidationException`
   `['sikep_user_id' => 'Pegawai SIKEP aktif tidak ditemukan.']` (Requirement 3.4).
4. `$user = User::syncFromSikep($sikep);` lalu `$user->setRole($data['role']);
   $user->aktif = true; $user->save();` (Requirement 3.2, 3.5 — `setRole`
   menyinkronkan `is_admin`).
5. Audit: `audit->record($aktor->sikep_user_id, 'pengguna.tarik', $user, [
   'target_sikep_user_id' => $user->sikep_user_id, 'role' => ['dari' => null,
   'ke' => $data['role']], 'aktif' => true])`.
6. `back()->with('success', 'Pegawai ditarik dan peran ditetapkan.')`.

### `ubahRole(User $user, Request $request)` — Requirement 4.1, 4.5, 4.6

Validasi `role` nullable dalam `User::ROLES` (izinkan `null` untuk mencabut
peran? Keputusan: `role` WAJIB salah satu dari `User::ROLES` — pencabutan total
peran dilakukan lewat `toggleAktif(false)` atau command, bukan UI ubah-role, agar
UI selalu memilih peran valid). Jadi:

```php
$data = $request->validate(['role' => ['required', Rule::in(User::ROLES)]]);
```

Guard anti-lockout:
- Bila `$user->id === $aktor->id` DAN `$data['role'] !== 'admin'` → tolak:
  "Anda tidak dapat menurunkan peran admin diri sendiri." (Requirement 4.5).
- Bila perubahan akan menyisakan **nol admin aktif** → tolak: "Minimal satu
  administrator aktif harus tersisa." (Requirement 4.6). Hitung:
  `User::where('role','admin')->where('aktif',true)->where('id','!=',$user->id)->count()`;
  bila 0 dan role baru bukan admin (atau user ini satu-satunya admin aktif yang
  diturunkan) → tolak.

Terapkan `setRole()`, `save()`, audit `pengguna.role` dengan `dari/ke`.

### `toggleAktif(User $user, Request $request)` — Requirement 4.2–4.6

Validasi `aktif` boolean. Guard:
- Bila `$user->id === $aktor->id` DAN `aktif === false` → tolak: "Anda tidak
  dapat menonaktifkan akun sendiri." (Requirement 4.5).
- Bila menonaktifkan admin dan akan menyisakan nol admin aktif → tolak (4.6).

Set `$user->aktif = $data['aktif']; $user->save();` (record TIDAK dihapus —
Requirement 4.3/NFR3). Audit `pengguna.aktif` dengan `dari/ke`.

### Error handling (controller)

Setiap kegagalan validasi/guard → `ValidationException` dengan pesan Indonesia
(ditampilkan di form/toast via flash). Operasi DB (`save`) yang gagal akan
melempar `QueryException` → ditangani handler global Laravel (500), tidak di-catch
di controller karena bukan kondisi yang bisa dipulihkan user. Perubahan sukses
di-flash `success` dan dirender toast di Vue (pola `usePage().props.flash` yang
sudah ada).

### `Admin/Pengguna.vue`

Pertahankan dua kartu (daftar user lokal + cari pegawai SIKEP). Ubah:
- Kolom "Peran" menampilkan satu `Tag` dari `data.role` (atau "—" bila null),
  severity: admin=`danger`, katimker=`info`, kabid=`warn`.
- Kolom "Status" baru: `Tag` Aktif/Nonaktif (success/secondary) dari `data.aktif`.
- Kolom aksi: `Dropdown`/`Select` PrimeVue untuk memilih role + tombol "Simpan"
  memanggil `pengguna.role`; `ToggleSwitch`/tombol memanggil `pengguna.aktif`
  dengan `confirm.require` (pola konfirmasi yang sudah ada).
- Kartu cari: hasil menampilkan tombol "Tarik" yang membuka dialog pemilihan role
  lalu POST `pengguna.tarik`. Untuk pegawai `sudah_ditarik === true`, tombol
  "Tarik" di-`disabled` dengan tooltip (`v-tooltip`) "Sudah ditarik — kelola di
  daftar user lokal"; tampilkan juga `Tag` kecil role lokalnya bila ada. Ini
  keputusan final (lihat §6 "Aturan UI untuk pegawai sudah_ditarik").
- Hapus teks "Peran Katimker/Kabid berasal dari SIKEP"; ganti dengan "Peran
  ditetapkan manual oleh administrator."

Gunakan komponen Sakai/PrimeVue yang sudah dipakai (`DataTable`, `Column`, `Tag`,
`Button`, `Select`, `Dialog`, `useToast`, `useConfirm`) — tidak membuat komponen
baru.

---

## 7. Penegakan `aktif = false` / `role = null` pada Sesi Berjalan

Masalah yang harus ditutup (Requirement 7.1/7.2/7.3): user yang dinonaktifkan /
kehilangan peran di tengah sesi harus di-logout dan diarahkan ke login dengan
pesan Indonesia pada request berikutnya, di semua route terautentikasi grup `auth`
— termasuk dashboard `/` yang (dikonfirmasi di `routes/web.php`) berada di grup
`['auth','two-factor']` TANPA `role:`.

**Batas cakupan yang eksplisit (bukan klaim menyeluruh):** `EnsureActiveRole`
hanya menegakkan status aktif/role untuk route yang BERADA DI DALAM grup `auth`.
Dua jalur sengaja berada DI LUAR grup auth dan karenanya TIDAK dicakup middleware
ini — keduanya ditangani terpisah dan tidak boleh diklaim tercakup oleh
`EnsureActiveRole`:

1. Route `two-factor.challenge` dan `two-factor.challenge.verify` (pending-2FA,
   belum `Auth::login()`). Celah "dinonaktifkan di tengah pending-2FA" ditutup
   oleh guard fail-closed di `TwoFactorController::verifyChallenge()` (lihat §4,
   subbagian "Celah pending-2FA"), BUKAN oleh middleware ini. Guard itu memakai
   pesan `EnsureActiveRole::MESSAGE` yang sama agar teks konsisten.
2. Route publik (login, dsb.) yang tidak butuh sesi.

Jadi penegakan "akun masih berhak" bersandar pada DUA titik yang saling
melengkapi: (a) `EnsureActiveRole` untuk seluruh request terautentikasi grup auth,
dan (b) guard `verifyChallenge()` untuk satu-satunya jalur login yang menembus
sesi tanpa melewati grup auth. Tidak ada jendela tersisa.

### Keputusan arsitektur: satu middleware tunggal `EnsureActiveRole`

Daripada menduplikasi logika logout di `EnsureRole` dan di `DashboardController`
(risiko divergensi pesan — melanggar NFR1), penegakan "akun masih berhak" diekstrak
ke SATU middleware baru `app/Http/Middleware/EnsureActiveRole.php`. Middleware ini:

- dipasang pada SELURUH grup `auth` (menggantikan ketergantungan pada `role:`
  untuk urusan aktif/role), sehingga dashboard pun tercakup;
- memakai SATU konstanta pesan sehingga teks tak pernah menyimpang;
- TIDAK mencegat route `logout`, `two-factor.challenge`, `two-factor.setup`,
  `two-factor.setup.confirm`, `two-factor.recovery-codes.regenerate` (daftar
  pengecualian eksplisit), agar user nonaktif tetap bisa logout dan jalur 2FA
  tidak macet.

`EnsureRole` (yang sudah ada, berbasis argumen `role:katimker` dsb.) TETAP ada dan
tugasnya menyempit menjadi HANYA otorisasi per-route: "apakah peran user termasuk
yang diizinkan untuk route ini". Ia tidak lagi perlu menangani kasus `roles=[]`
untuk logout karena `EnsureActiveRole` sudah menanganinya lebih dulu (lihat urutan
di bawah). `EnsureRole` tetap `abort(403)` untuk kasus "punya peran tapi bukan
peran yang diizinkan" (mis. katimker membuka route admin).

### Urutan middleware (ditegaskan)

Pada grup `auth`, urutan terpasang WAJIB: `auth` → `EnsureActiveRole` →
`two-factor` → (sub-grup) `role:`. Artinya:

1. `auth` memastikan ada sesi.
2. `EnsureActiveRole` dijalankan SEBELUM `two-factor` dan SEBELUM `role:`. Jadi
   user nonaktif/`role=null` SELALU di-logout lebih dulu di semua route auth
   (termasuk dashboard) sebelum logika 2FA relevan. Ini menutup celah yang
   disebut review: admin yang baru dinonaktifkan tidak bergantung pada urutan
   guard controller — ia sudah dilempar keluar oleh `EnsureActiveRole`.
3. `two-factor` (`EnsureTwoFactor`) hanya berjalan untuk user yang masih aktif &
   ber-peran; `requiresTwoFactor()` yang kini membaca role lokal tetap konsisten.
4. `role:` (`EnsureRole`) melakukan otorisasi per-route terakhir.

Pendaftaran di `routes/web.php`: tambahkan `EnsureActiveRole` ke array middleware
grup auth, DI DEPAN `two-factor`:

```php
Route::middleware(['auth', 'active-role', 'two-factor'])->group(function () { ... });
```

Alias `active-role` didaftarkan di `bootstrap/app.php`
(`$middleware->alias(['active-role' => \App\Http\Middleware\EnsureActiveRole::class])`),
mengikuti pola alias `role`/`two-factor` yang sudah ada.

### Mengapa redirect + `withErrors`, BUKAN `ValidationException->redirectTo()`

Review menandai (Temuan 1) bahwa melempar `ValidationException(...)->redirectTo()`
dari middleware pada request **Inertia GET** belum terbukti menampilkan pesan di
halaman login, dan urutan flash-vs-`session()->invalidate()` berisiko menghapus
flash. Diverifikasi terhadap kode nyata: halaman `resources/js/Pages/Auth/Login.vue`
HANYA merender `form.errors.login` (komponen `Message` + `small`), dan tidak
membaca prop `status`/`flash`. Maka keputusan final memakai jalur yang pasti
bekerja dengan halaman login yang ada: lakukan logout penuh LEBIH DULU, regenerasi
sesi, BARU flash error ke bag `login` lewat `redirect()->withErrors()` (yang
di-share Inertia sebagai prop `errors`, dibaca `form.errors.login`). Urutan ini
benar karena `withErrors()` mem-flash ke sesi BARU hasil `regenerateToken()`,
bukan ke sesi yang baru saja di-`invalidate()`.

`EnsureActiveRole::handle()` (imports standar middleware: `Closure`,
`Illuminate\Http\Request`, `Symfony\Component\HttpFoundation\Response`, ditambah
`Illuminate\Support\Facades\Auth`):

```php
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveRole
{
    /** Pesan tunggal (sumber kebenaran teks) — NFR1. */
    public const MESSAGE = 'Akun Anda dinonaktifkan atau tidak memiliki peran. Hubungi administrator.';

    /** Route yang tidak boleh dicegat agar logout & jalur 2FA tetap jalan. */
    private const EXCEPT = [
        'logout',
        'two-factor.challenge',
        'two-factor.setup',
        'two-factor.setup.confirm',
        'two-factor.recovery-codes.regenerate',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request); // 'auth' yang menangani tamu
        }

        if (in_array($request->route()?->getName(), self::EXCEPT, true)) {
            return $next($request);
        }

        // Fail-closed: roles() kini membaca role lokal (§3). Nonaktif / role=null
        // => roles=[] => tidak berhak melanjutkan sesi.
        if ($user->roles()['roles'] === []) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['login' => self::MESSAGE]);
        }

        return $next($request);
    }
}
```

`EnsureRole::handle()` disederhanakan (tidak lagi menangani `roles=[]` untuk
logout, karena sudah ditangani `EnsureActiveRole` yang berjalan lebih dulu):

```php
$user = $request->user();
if ($user === null) {
    abort(403);
}
$userRoles = $user->roles()['roles'];
if (count(array_intersect($roles, $userRoles)) === 0) {
    abort(403); // punya peran, tapi bukan yang diizinkan untuk route ini
}
return $next($request);
```

### Dampak ke `DashboardController`

Karena `EnsureActiveRole` kini mencegat dashboard, TIDAK ada guard logout yang
ditambahkan di `DashboardController::index()`. Controller dibiarkan apa adanya
(hanya me-resolve `roles` untuk memilih blok data). Ini menghapus duplikasi logika
yang disorot review (Temuan 2) — `DashboardController` tidak lagi masuk daftar
file yang diubah untuk urusan penegakan sesi.

### Interaksi dengan 2FA/logout (ditegaskan)

Karena `EnsureActiveRole` terpasang SEBELUM `two-factor` dan mengecualikan route
`logout` + `two-factor.*`, maka: (a) untuk user yang SUDAH `Auth::login()`, user
nonaktif selalu di-logout sebelum `EnsureTwoFactor` sempat mengevaluasi
`requiresTwoFactor()`, menutup skenario "admin nonaktif lolos karena
`requiresTwoFactor()` kini false"; (b) user yang sedang di jalur 2FA tetap dapat
logout dan menyelesaikan/membatalkan challenge tanpa dicegat. Klaim "tidak
terganggu" kini bertumpu pada urutan middleware yang eksplisit, bukan pada urutan
guard controller.

Penting — pending-2FA (belum `Auth::login()`): karena route challenge berada DI
LUAR grup auth (lihat "Batas cakupan yang eksplisit" di atas), penonaktifan yang
terjadi SELAMA pending-2FA TIDAK ditangani di sini melainkan oleh guard
fail-closed di `verifyChallenge()` (§4). Dengan dua titik ini, seluruh transisi
"aktif → nonaktif" tertutup: sebelum login (gerbang §4 langkah 3), selama
pending-2FA (guard `verifyChallenge()`), dan setelah login (`EnsureActiveRole`).

### Testability

Feature test (non-mock): buat `User::factory()->katimker()->create()`, login,
akses route ber-peran (sukses), lalu `->update(['aktif' => false])`, akses lagi →
assert redirect ke `login`, `assertGuest()`, dan `assertSessionHasErrors('login')`.
Ulangi untuk kasus `role` di-set `null` di tengah sesi (Requirement 7.2) dan untuk
route dashboard `/` (membuktikan cakupan tanpa `role:`). Satu test khusus:
request Inertia GET (header `X-Inertia`) ke dashboard untuk user nonaktif → assert
redirect 409/302 ke login dan pesan muncul via prop `errors.login`.

---

## 8. ApproverMapping Berbasis Role Lokal

File: `app/Services/ApproverMappingService.php` (controller & Vue hanya
disesuaikan bila perlu).

Keputusan: sumber kandidat Katimker/Kabid beralih dari
`SikepJabatanLapanganUser` ke tabel `users` lokal (`role` + `aktif`). Kunci data
tetap `sikep_user_id` (Requirement 5.4, Out of Scope — tabel `approver_mappings`
tidak dirombak). Bentuk keluaran `listKatimker()`/`listKabid()` TIDAK berubah
sehingga `ApproverMappingController` dan `Admin/ApproverMapping.vue` tidak perlu
diubah strukturnya.

`listKatimker()`:

```php
$katimkerUsers = User::query()
    ->where('role', User::ROLE_KATIMKER)
    ->where('aktif', true)
    ->get()
    ->keyBy('sikep_user_id');

$mappings = ApproverMapping::query()
    ->whereIn('katimker_sikep_user_id', $katimkerUsers->keys())
    ->where('aktif', true)->get()->keyBy('katimker_sikep_user_id');

$kabidIds = $mappings->pluck('kabid_sikep_user_id')->unique()->filter()->values();
$kabidUsers = User::query()->whereIn('sikep_user_id', $kabidIds)->get()->keyBy('sikep_user_id');

return $katimkerUsers->map(function (User $u) use ($mappings, $kabidUsers) {
    $kabidId = $mappings->get($u->sikep_user_id)?->kabid_sikep_user_id;
    $kabidUser = $kabidId ? $kabidUsers->get($kabidId) : null;

    // Validitas pemetaan: kabid masih user ber-role 'kabid' DAN aktif.
    $kabidValid = $kabidUser !== null
        && $kabidUser->role === User::ROLE_KABID
        && $kabidUser->aktif === true;

    return [
        'sikep_user_id' => $u->sikep_user_id,
        'nama' => $u->nama,
        'username' => $u->username,
        'no_absen' => $u->no_absen,
        'kabid_sikep_user_id' => $kabidId,
        'kabid_nama' => $kabidUser?->nama,          // nama historis tetap tampil
        'kabid_valid' => $kabidValid,                // penanda validitas untuk UI
    ];
})->sortBy('nama')->values();
```

(Gunakan field `users.nama` sebagai pengganti `SikepUser::nama_lengkap`; nilai ini
sudah dinamai lengkap saat `syncFromSikep`.)

`listKabid()`: `User::where('role','kabid')->where('aktif',true)` → map ke
`['sikep_user_id'=>$u->sikep_user_id,'nama'=>$u->nama]`.

`isKatimker()`/`isKabid()`:

```php
public function isKatimker(int $sikepUserId): bool
{
    return User::where('sikep_user_id', $sikepUserId)
        ->where('role', User::ROLE_KATIMKER)->where('aktif', true)->exists();
}
public function isKabid(int $sikepUserId): bool
{
    return User::where('sikep_user_id', $sikepUserId)
        ->where('role', User::ROLE_KABID)->where('aktif', true)->exists();
}
```

`assign()`: logika tidak berubah; validasi `isKatimker`/`isKabid` kini otomatis
memakai role lokal (Requirement 5.3). Pesan error disesuaikan agar tidak menyebut
SIKEP:
- `'Pengguna bukan Katimker aktif.'`
- `'Target penilai bukan Kabid aktif.'`

Konstruktor `ApproverMappingService` menghapus dependensi tak terpakai
(`SikepJabatanLapanganUser`, `SikepUser`) dari `use`; `AuditLogger` tetap.
`SikepUser` boleh tetap diimpor bila masih dipakai di tempat lain file, tetapi
di service ini tidak lagi.

Edge case & keputusan display (final — menutup Temuan 9 review):

- **Katimker aktif tanpa pemetaan**: tetap muncul dengan `kabid_sikep_user_id=null`
  dan `kabid_valid=false` (konsisten `unassigned()`).
- **Kabid pemetaan dinonaktifkan ATAU role-nya berubah (bukan lagi `kabid`)**:
  `kabidUsers = User::whereIn('sikep_user_id', $kabidIds)` sengaja TIDAK memfilter
  `role`/`aktif` saat MENGAMBIL NAMA, sehingga `kabid_nama` historis tetap tampil
  (menghindari baris "nama hilang"). Namun field BARU `kabid_valid` (boolean)
  menandai apakah kabid itu MASIH `role='kabid'` dan `aktif=true`. Keputusan:
  bila `kabid_valid=false`, UI `Admin/ApproverMapping.vue` menampilkan
  `kabid_nama` disertai badge/`Tag` "tidak valid" (mis. severity `warn`, teks
  "bukan Kabid aktif"), sehingga admin tahu pemetaan perlu diperbarui. Ini
  keputusan eksplisit, bukan sekadar "nama tetap tampil".
- Validasi `assign()` baru tetap menolak target yang bukan Kabid aktif, jadi
  pemetaan tak-valid tak bisa dibuat/diperbarui ke kondisi tak-valid; `kabid_valid`
  murni untuk menandai data historis yang sudah menyimpang.

Dampak kontrak props: `listKatimker()` menambah SATU field `kabid_valid`. Ini
penambahan aditif (tidak menghapus field lama), sehingga
`ApproverMappingController` tidak berubah, tetapi `Admin/ApproverMapping.vue`
PERLU penyesuaian kecil untuk merender badge saat `kabid_valid=false` (lihat
"Daftar File yang Berubah").

---

## 9. Test & Factory

File: `database/factories/UserFactory.php` dan `tests/`.

### UserFactory (Requirement 10.1)

Tambah `role => null`, `aktif => true` di `definition()`. Tambah state yang
menjaga invarian:

```php
public function admin(): static    { return $this->state(['role' => 'admin', 'is_admin' => true, 'aktif' => true]); }
public function katimker(): static { return $this->state(['role' => 'katimker', 'is_admin' => false, 'aktif' => true]); }
public function kabid(): static    { return $this->state(['role' => 'kabid', 'is_admin' => false, 'aktif' => true]); }
public function nonaktif(): static { return $this->state(['aktif' => false]); }
```

State `admin()` yang lama (`is_admin => true`) dilengkapi `role => 'admin'` agar
`RoleResolver` baru mengenalinya.

### Test yang mesti diperbarui (Requirement 10.2)

- `tests/Unit/RoleResolverTest.php`: user dibuat dengan `['sikep_user_id'=>999999,
  'is_admin'=>true]` → tambahkan `'role'=>'admin','aktif'=>true` (atau pakai
  `->admin()`), karena resolver kini membaca `role`. Tambah kasus: user `aktif=false`
  → peran kosong; user `role=null` → peran kosong; user `role='katimker'` →
  `is_katimker=true`.
- `tests/Feature/LoginTest.php`:
  - `test_login_sukses_...`: controller kini mewajibkan record lokal SEBELUM
    login. Test ini mem-mock `RoleResolver` dan mengharapkan user dibuat oleh
    controller. Karena alur baru menolak bila record lokal belum ada, test harus
    membuat `User::factory()->katimker()->create(['sikep_user_id'=>101,...])`
    lebih dulu, lalu `assertDatabaseHas`. Mock `RoleResolver` tetap boleh untuk
    isolasi, tapi record lokal nyata diperlukan untuk lolos gerbang langkah 3.
  - `test_login_akun_tanpa_peran_ditolak`: ganti skenario menjadi "record lokal
    ada dengan `role=null`" (atau "tidak ada record lokal"), dan assert pesan
    spesifik 2.2/2.4. `assertDatabaseCount('users',0)` hanya valid untuk kasus
    "tidak ada record lokal".
  - **String pesan login lama**: `LoginController` saat ini memakai teks "Akun
    Anda belum memiliki peran (Katimker/Kabid/Admin). Hubungi administrator."
    (dikonfirmasi di kode). Desain §4 menyederhanakannya menjadi tiga pesan baru.
    Setiap test yang meng-`assert` substring/teks lama itu WAJIB diperbarui ke
    teks baru yang sesuai (2.2/2.3/2.4). Cari dengan grep pola "memiliki peran
    (Katimker" di `tests/` sebelum implementasi dan perbarui semua kecocokan.
  - Tambah test (Requirement 10.3): tidak ada record lokal → pesan 2.2; record
    `aktif=false` → pesan 2.3; record `role=null` → pesan 2.4; record valid →
    sukses + role dari kolom lokal.
- Test fitur yang mem-mock `RoleResolver` (`ApproverMappingTest`,
  `AttachmentAccessTest`, `IncentiveConfigTest`, `AssignmentTest`, `RecapTest`,
  `IndividualTargetTest`, `KabidReviewTest`, `KatimkerSubmitTest`,
  `KatimkerTaskTest`, `ReclassifyTest`, `RevisiCycleTest`, `TwoFactorAuthTest`):
  mock tetap valid karena bentuk keluaran resolver tidak berubah. Namun user
  factory yang dipakai sebaiknya mendapat `role`/`aktif` konsisten (via state)
  agar bila ada test yang TIDAK mem-mock resolver (mis. `EnsureActiveRole` nyata)
  tetap lolos. Audit tiap test: pastikan user yang dibuat aktif dan ber-role
  sesuai peran yang di-mock, agar `EnsureActiveRole` (logout saat `roles=[]`)
  tidak memicu redirect tak terduga. Karena resolver di-mock mengembalikan peran
  non-kosong, `EnsureActiveRole` tidak akan logout; aman. (Catatan: mock resolver
  juga menenangkan `EnsureActiveRole` karena keduanya memanggil `roles()`.)
- `ApproverMappingTest`: kandidat kini dari user lokal. Perbarui setup agar
  membuat `User::factory()->katimker()` dan `->kabid()` alih-alih menyeed
  `SikepJabatanLapanganUser`. Validasi `assign()` diuji dengan user lokal ber-role.
- **Test props `UserController::index()`**: kontrak props berubah (dari
  `is_admin/is_katimker/is_kabid` menjadi `role`+`aktif`+`roleOptions`, dan
  `hasilCari` kini `sudah_ditarik`+`role`). Jika ada test yang meng-assert bentuk
  props lama, perbarui; jika belum ada, TAMBAHKAN feature test `index()` yang
  meng-assert prop baru via `Inertia\Testing\AssertableInertia`
  (`->has('users.0.role')`, `->has('users.0.aktif')`, `->has('roleOptions')`,
  dan `hasilCari.*.sudah_ditarik`).
- **`tests/Feature/AdminManagementTest.php` (WAJIB — menutup Temuan review 2)**:
  file ini (diverifikasi langsung) memanggil route `admin.pengguna.set-admin`
  yang DIHAPUS desain, sehingga dua test akan ERROR (`RouteNotFoundException`),
  bukan sekadar gagal assert. Perbaikan konkret:
  - `test_admin_menjadikan_pengguna_lain_admin()`: alihkan dari
    `post(route('admin.pengguna.set-admin'), ['sikep_user_id'=>222,'is_admin'=>true])`
    ke endpoint baru `post(route('admin.pengguna.role', $target), ['role'=>'admin'])`
    (route-model-binding pakai PK lokal `$target->id`, bukan `sikep_user_id`).
    Buat target dengan state ber-role: `User::factory()->katimker()->create(['sikep_user_id'=>222])`
    (bukan `['is_admin'=>false]` langsung) agar invarian 1.5 terjaga saat resolver
    dibaca nyata. Assert `$target->refresh()->is_admin === true` dan
    `$target->refresh()->role === 'admin'`. Ganti assertion audit dari
    `['aksi'=>'role.update']` menjadi `['aksi'=>'pengguna.role']` (sesuai §6).
  - `test_admin_tidak_bisa_mencabut_admin_diri_sendiri()`: alihkan ke guard
    anti-lockout baru. POST `route('admin.pengguna.role', $self)` dengan
    `['role'=>'katimker']` → `assertSessionHasErrors('role')` (menggantikan
    `assertSessionHasErrors('is_admin')`), pesan "Anda tidak dapat menurunkan
    peran admin diri sendiri." Assert admin tetap `role==='admin'`. (Opsional
    tambahan: uji `route('admin.pengguna.aktif', $self)` dengan `['aktif'=>false]`
    → `assertSessionHasErrors('aktif')`, pesan "Anda tidak dapat menonaktifkan
    akun sendiri.")
  - `actingAsAdmin()` helper: ganti `User::factory()->create(['is_admin'=>true, ...])`
    menjadi `User::factory()->admin()->create([...])` agar `role='admin'` ikut
    terisi dan konsisten dengan resolver baru (mock resolver yang ada tetap
    dipertahankan untuk isolasi).
  - Tiga test lain di file ini (`...membuka_manajemen_pengguna`, `...periode`,
    `...integrasi`, `non_admin_ditolak`) tidak memakai endpoint yang dihapus;
    hanya selaraskan factory ke state ber-role bila perlu agar `EnsureActiveRole`
    nyata (jika tidak di-mock) tidak memicu logout.
- **Komponen Vue `Admin/Pengguna.vue`**: hasil grep menunjukkan proyek ini tidak
  memiliki unit test komponen Vue (tidak ada test runner JS terpasang untuk
  komponen). Maka perubahan `Pengguna.vue` dan `ApproverMapping.vue` diverifikasi
  secara MANUAL (render props baru + aksi tarik/ubah/toggle). Nyatakan ini di
  laporan implementasi; jangan asumsikan ada test Vue otomatis.

### Test baru (Requirement 10.3)

- Gerbang login 2.2–2.5 (di `LoginTest`).
- Toggle aktif + anti-lockout 4.5–4.6 (feature test `UserController`): admin
  tak bisa menonaktifkan diri; tak bisa menurunkan role diri; aksi yang
  menyisakan nol admin aktif ditolak.
- Penegakan sesi 7.1/7.2 (feature test `EnsureActiveRole`): user aktif ber-role
  mengakses route ber-peran lalu di-set `aktif=false` → request berikutnya
  redirect ke `login`, `assertGuest()`, `assertSessionHasErrors('login')`.
  Tambah kasus `role=null` di tengah sesi (7.2), kasus route dashboard `/`
  (membuktikan cakupan tanpa `role:`), dan satu kasus request Inertia GET
  (header `X-Inertia`) yang meng-assert pesan muncul via prop `errors.login`.
- **Pending-2FA dinonaktifkan di tengah (menutup Temuan review 1)**: feature test
  di `TwoFactorAuthTest` (atau `LoginTest`). Siapkan admin dengan 2FA
  terkonfirmasi (`->admin()` + `two_factor_confirmed_at`), set sesi
  `2fa.pending_user_id` = id admin itu (mensimulasikan state setelah
  `store()`), lalu `->update(['aktif' => false])` (atau `setRole(null)`), POST
  `two-factor.challenge.verify` dengan kode TOTP valid → assert `assertGuest()`,
  redirect ke `login`, `assertSessionHasErrors('login')`, dan BUKAN redirect ke
  `dashboard`. Resolver boleh di-mock mengembalikan `roles=[]` untuk id itu agar
  guard `verifyChallenge()` terpicu secara deterministik.
- Validasi ApproverMapping berbasis role 5.3 (di `ApproverMappingTest`).
- **Non-regresi NON-MOCK alur tugas (Requirement 9/15 — menutup Temuan 8)**:
  minimal satu feature test per peran TANPA mem-mock `RoleResolver`:
  - Katimker: `User::factory()->katimker()->create()` nyata → login → POST
    `katimker.tugas.store` → assert sukses (menembus `EnsureActiveRole` +
    `EnsureRole` + resolver baru dari kolom `role`).
  - Kabid: `User::factory()->kabid()->create()` nyata → akses + aksi review
    (`kabid.penilaian.approve`/`reject`) pada tugas binaannya → assert sukses.
  Ini membuktikan jalur `role:` → `EnsureRole` → `roles()` → RoleResolver baru
  bekerja pada sumber role lokal, bukan hanya pada mock.
- **Backfill + bootstrap admin (menutup Temuan 11)**: feature/integration test
  yang membuat beberapa user non-admin (tanpa admin sama sekali), menjalankan
  migrasi backfill (`Artisan::call('migrate')` pada DB test / `RefreshDatabase`
  + jalankan backfill), lalu `Artisan::call('app:set-admin', ['login' => ...])`,
  dan assert: semua non-admin jadi `aktif=false,role=null`; user yang di-set
  command jadi `role=admin,is_admin=true,aktif=true` dan BISA login.

### Testability (ringkasan)

- Unit: `RoleResolver` (resolusi dari kolom), `User::setRole()` (invarian).
- Integration/feature: gerbang login (3 pesan), Kepegawaian (tarik/ubah
  role/toggle + anti-lockout + audit + props `index()`), `EnsureActiveRole`
  logout (termasuk Inertia GET & dashboard), ApproverMapping kandidat/validasi,
  backfill+bootstrap admin.
- Non-regresi NON-MOCK (Requirement 9/15): `katimker.tugas.store` dan
  `kabid.penilaian.*` dengan user factory ber-role NYATA (tanpa mock resolver).
- Non-regresi mock yang sudah ada (`KatimkerSubmitTest`, `KabidReviewTest`,
  `ReclassifyTest`, `RevisiCycleTest`, `AssignmentTest`) tetap hijau dengan
  factory ber-role.
- Verifikasi MANUAL: komponen Vue `Pengguna.vue` & `ApproverMapping.vue` (tak ada
  test runner komponen di proyek).

---

## Daftar File yang Berubah

Baru:
- `database/migrations/2026_10_08_100001_add_role_and_aktif_to_users_table.php`

Diubah:
- `app/Models/User.php` — fillable `role`,`aktif`; cast `aktif`; konstanta
  `ROLE_*`/`ROLES`; `setRole()`. (`syncFromSikep` tidak diubah.)
- `app/Services/Sikep/RoleResolver.php` — resolve dari `users.role`+`aktif`,
  fail-closed; lepas dari `SikepJabatanLapanganUser`.
- `app/Http/Controllers/Auth/LoginController.php` — gerbang 3 cabang + pesan ID,
  Invarian Urutan dipertahankan.
- `app/Http/Controllers/Auth/TwoFactorController.php` — inject `RoleResolver`;
  guard fail-closed di `verifyChallenge()` sebelum `Auth::login()` (tolak login
  bila `roles=[]` saat pending-2FA, pesan `EnsureActiveRole::MESSAGE`).
- `app/Console/Commands/SetAdmin.php` — pakai `setRole()`; `--off`→`role=null`,
  `aktif` tak diubah; non-`--off`→admin+aktif.
- `app/Http/Controllers/Admin/UserController.php` — `tarik`, `ubahRole`,
  `toggleAktif`, props baru, audit, anti-lockout. (`setAdmin` dihapus.)
- `resources/js/Pages/Admin/Pengguna.vue` — UI role+status+tarik.
- `app/Services/ApproverMappingService.php` — kandidat dari users lokal; pesan ID.
- `app/Http/Middleware/EnsureRole.php` — disederhanakan jadi otorisasi per-route
  saja (`abort(403)` untuk peran tak cocok); logika logout dipindah ke
  `EnsureActiveRole`.
- `routes/web.php` — tambah alias `active-role` ke grup auth (di depan
  `two-factor`); route `pengguna.tarik`/`pengguna.role`/`pengguna.aktif`; hapus
  `pengguna.set-admin`.
- `bootstrap/app.php` — daftarkan alias middleware `active-role` →
  `EnsureActiveRole` (pola sama dgn `role`/`two-factor`).
- `resources/js/Pages/Admin/ApproverMapping.vue` — render badge "bukan Kabid
  aktif" saat `kabid_valid === false` (penyesuaian kecil, aditif).
- `database/factories/UserFactory.php` — `role`/`aktif` + state.
- `tests/**` — perbarui & tambah seperti §9.

Baru (middleware):
- `app/Http/Middleware/EnsureActiveRole.php` — logout+redirect saat `roles=[]`
  untuk SEMUA route auth; pesan dari satu konstanta; mengecualikan
  logout/two-factor.*.

Tidak diubah (eksplisit):
- `app/Models/Sikep/SikepUser.php` — konstanta jabatan & helper dipertahankan.
- Tabel/model `ApproverMapping` — struktur & kunci `sikep_user_id` tetap.
- `app/Http/Middleware/EnsureTwoFactor.php` — perilaku 2FA tetap (bergantung
  `requiresTwoFactor()` yang kontraknya tak berubah).
- `app/Http/Controllers/DashboardController.php` — TIDAK diubah; penegakan sesi
  kini di `EnsureActiveRole`, bukan guard controller (lihat §7).
- `app/Http/Controllers/Admin/ApproverMappingController.php` — struktur props tak
  berubah; `listKatimker()` hanya menambah field aditif `kabid_valid`, controller
  tetap meneruskannya apa adanya tanpa perubahan kode.

---

## Catatan Edge Case & Invarian

- **Nol admin global**: dijaga di UI (`ubahRole`/`toggleAktif` guard 4.6).
  Command CLI tidak menjaga ini (bootstrap operator) — didokumentasikan.
- **Self-lockout**: dijaga di UI (tak bisa nonaktif/turunkan diri). Command
  `--off` pada diri sendiri diizinkan (operator CLI sadar risiko).
- **Sinkron `is_admin`↔`role`**: satu-satunya jalur tulis `is_admin`/`role` yang
  hidup adalah `setRole()`; `UserController::setAdmin()` lama DIHAPUS; backfill
  migrasi & `UserFactory` state menulis kedua kolom berpasangan secara eksplisit.
- **Fail-closed resolver**: query lokal gagal / user tak ada / nonaktif / role
  null → peran kosong (lebih aman daripada memberi akses).
- **Penegakan sesi**: `EnsureActiveRole` (middleware tunggal, SEBELUM
  `two-factor` & `role:`) men-logout user `roles=[]` di semua route auth termasuk
  dashboard; mengecualikan logout & two-factor.*. Pesan dari satu konstanta.
- **Celah pending-2FA**: route `two-factor.challenge[.verify]` berada DI LUAR grup
  auth sehingga tak dicakup `EnsureActiveRole`. Ditutup oleh guard fail-closed di
  `TwoFactorController::verifyChallenge()` yang me-resolve role lokal SEBELUM
  `Auth::login()` dan menolak bila `roles=[]`, memakai pesan
  `EnsureActiveRole::MESSAGE` yang sama (§4). Dengan ini transisi aktif→nonaktif
  tertutup pada ketiga fase: pra-login, pending-2FA, pasca-login.
- **Pemetaan Kabid tak valid**: `listKatimker()` menandai `kabid_valid=false`
  bila kabid pemetaan tak lagi `role='kabid'` aktif; UI menampilkan badge "bukan
  Kabid aktif". `assign()` tetap menolak target tak valid.
- **Jalur panas bebas SIKEP**: login (setelah verifikasi password) dan middleware
  per-request hanya menyentuh tabel lokal (NFR5).
- **Retensi**: menonaktifkan user tidak menghapus record (NFR3).
- **Audit**: `tarik`, `role`, `aktif` tercatat dengan `dari→ke` + aktor + target
  `sikep_user_id` (NFR2).

---

## Respons terhadap Temuan Review (iterasi 2)

Verdict review sebelumnya: **CHANGES_REQUESTED** (1 HIGH, 10 MEDIUM, 2 NIT).
Setiap temuan ditangani di revisi ini; ringkasan:

1. **[HIGH] Perilaku `ValidationException->redirectTo()` di middleware untuk
   Inertia GET.** DITANGANI. §7 kini TIDAK melempar `ValidationException` dari
   middleware. `EnsureActiveRole` melakukan `Auth::logout()` →
   `session()->invalidate()` → `regenerateToken()` LALU
   `redirect()->route('login')->withErrors(['login' => self::MESSAGE])`. Urutan
   flash SETELAH sesi baru. Diverifikasi terhadap kode: `Auth/Login.vue` merender
   `form.errors.login` (bukan prop `status`), jadi `withErrors` adalah jalur yang
   pasti tampil. Ditambah feature test Inertia GET (§9).
2. **[MEDIUM] Duplikasi guard dashboard + kalimat menggantung.** DITANGANI.
   Logika diekstrak ke SATU middleware `EnsureActiveRole` yang mencakup dashboard;
   pesan dari satu konstanta `EnsureActiveRole::MESSAGE`. Kalimat eksploratif
   "daftarkan `EnsureRole` tanpa argumen tidak memungkinkan…" DIHAPUS; guard di
   `DashboardController` dibatalkan.
3. **[MEDIUM] Interaksi penegakan sesi vs 2FA (urutan middleware).** DITANGANI.
   §7 menegaskan urutan `auth → active-role → two-factor → role:`; penegakan
   `roles=[]` berjalan SEBELUM `two-factor` dan independen darinya, dengan
   pengecualian route `logout`/`two-factor.*`.
4. **[MEDIUM] Klaim stack Laravel 12 salah.** DITANGANI. Ikhtisar diganti ke
   "Laravel 13 (v13.18.x; `^13.8`)", diverifikasi dari `composer.json`/`.lock`.
5. **[MEDIUM] Ambiguitas sumber pesan login (§3 resolver vs §4 cek eksplisit).**
   DITANGANI. §4 langkah 3 ditegaskan sebagai SATU-SATUNYA penentu pesan
   2.2/2.3/2.4 (query memuat `role` & `aktif` eksplisit); resolver langkah 4 hanya
   untuk `is_admin` cabang 2FA.
6. **[MEDIUM] Celah invarian `is_admin↔role` (method `setAdmin` lama).**
   DITANGANI. §1 menambah "Aturan jalur-tulis"; §6 + Daftar File menyatakan HAPUS
   method `UserController::setAdmin()` beserta route; satu-satunya penulis
   `is_admin` yang hidup adalah `setRole()` (plus backfill & factory berpasangan).
7. **[MEDIUM] Rencana test tak lengkap (props `index()` & string pesan lama).**
   DITANGANI. §9 menambah: pembaruan test string pesan login lama "...peran
   (Katimker/Kabid/Admin)..."; feature test props baru `index()`
   (`role`/`aktif`/`roleOptions`/`sudah_ditarik`); catatan tak ada test Vue →
   verifikasi manual.
8. **[MEDIUM] Non-regresi alur tugas hanya via mock.** DITANGANI. §9 menambah
   feature test NON-MOCK per peran: katimker nyata → `katimker.tugas.store`;
   kabid nyata → `kabid.penilaian.*`.
9. **[MEDIUM] Display mapping saat Kabid berubah role.** DITANGANI. §8 menambah
   field `kabid_valid`; UI menampilkan badge "bukan Kabid aktif" bila `false`.
   Keputusan display eksplisit.
10. **[MEDIUM] Aturan UI pegawai `sudah_ditarik` ambigu.** DITANGANI. §6
    menetapkan: tombol "Tarik" DISABLED + tooltip "Sudah ditarik — kelola di
    daftar user lokal"; `tarik()` menolak record yang sudah ada (pertahanan
    server).
11. **[MEDIUM] Prosedur deploy backfill vs `app:set-admin`.** DITANGANI. §1
    menetapkan prosedur tunggal (deploy → migrate → `app:set-admin` → admin tarik
    user) + feature test backfill tanpa admin.
12. **[NIT] `syncFromSikep` record baru `aktif=true` walau `role=null`.**
    DITANGANI (dicatat). §2 poin 4 menjelaskan tidak memberi akses dan jalur
    normal selalu set `role`+`aktif` berpasangan; default skema `aktif=true`
    dipertahankan sesuai Req 1.1.
13. **[NIT] `IndividualTargetController` tak eksplisit di §3.** DITANGANI.
    Ditambahkan ke daftar konsumen `roles()` di §3.

---

## Respons terhadap Temuan Review (iterasi 3)

Verdict review iterasi 3: **CHANGES_REQUESTED** (2 MEDIUM + 1 NIT). Semua temuan
diverifikasi ulang langsung ke kode nyata sebelum ditangani; semuanya DITANGANI.

1. **[MEDIUM] Celah penonaktifan di tengah alur pending-2FA (check b).**
   DITANGANI. Diverifikasi di kode: route `two-factor.challenge[.verify]` memang
   di luar grup auth (`routes/web.php` baris 34–38) dan `verifyChallenge()` tidak
   mengecek `aktif`/`role` sebelum `Auth::login()`. Opsi A (disarankan review)
   diambil: §4 menambah subbagian "Celah pending-2FA" dengan guard fail-closed di
   `verifyChallenge()` yang me-resolve role lokal SEBELUM `Auth::login()` (setelah
   kode terverifikasi) dan menolak login bila `roles=[]`, memakai pesan tunggal
   `EnsureActiveRole::MESSAGE`. §7 dikoreksi: klaim "SEMUA route auth" diganti
   dengan "Batas cakupan yang eksplisit" yang menyatakan jalur challenge TIDAK
   dicakup middleware dan ditangani oleh guard `verifyChallenge()`. Controller
   `TwoFactorController` ditambahkan ke Daftar File (inject `RoleResolver`), dan
   §9 menambah feature test pending-2FA + `aktif=false` → `assertGuest()`.

2. **[MEDIUM] `AdminManagementTest.php` tak masuk rencana test (check c).**
   DITANGANI. Diverifikasi: kedua test (`test_admin_menjadikan_pengguna_lain_admin`,
   `test_admin_tidak_bisa_mencabut_admin_diri_sendiri`) memanggil
   `route('admin.pengguna.set-admin')` yang dihapus. §9 menambah butir eksplisit
   `tests/Feature/AdminManagementTest.php`: alihkan ke endpoint
   `admin.pengguna.role`/`admin.pengguna.aktif` (route-model-binding PK lokal),
   ganti assertion audit `'role.update'` → `'pengguna.role'`,
   `assertSessionHasErrors('is_admin')` → `('role')`/`('aktif')`, dan selaraskan
   `actingAsAdmin()`/factory ke state `->admin()`/`->katimker()`.

3. **[NIT] Nama audit action baru berbeda dari `role.update` lama.** DITANGANI.
   §6 menambah "Catatan nama aksi audit": grep diverifikasi menunjukkan
   `'role.update'` hanya dipakai `UserController::setAdmin()` (dihapus) dan
   `AdminManagementTest.php` (ditangani §9); tidak ada konsumen lain. Instruksi
   grep ulang sebelum implementasi ditambahkan di §6.
