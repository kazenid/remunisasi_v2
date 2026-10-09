# Desain Teknis — 2FA (TOTP) Wajib untuk Admin

## Ringkasan

Fitur ini menambahkan autentikasi dua faktor (2FA) berbasis TOTP (RFC 6238, authenticator app) yang **wajib** untuk pengguna dengan flag `is_admin = true`, tanpa mengubah jalur login pengguna non-admin (katimker/kabid) sama sekali. 2FA disisipkan ke dalam alur login kustom SIKEP yang sudah ada di `LoginController`, bukan menggantinya. Verifikasi password tetap sepenuhnya ditangani `SikepAuthService` (hash SIKEP, blocklist master password), penolakan akun tanpa peran tetap berlaku, dan pesan generik "Kredensial yang diberikan tidak cocok" dipertahankan apa adanya.

Dua kondisi admin diperlakukan berbeda setelah password benar:

1. **Admin dengan 2FA sudah dikonfirmasi** (`two_factor_confirmed_at` tidak null): sesi **tidak** difinalisasi. Alih-alih memanggil `Auth::login`, controller menyimpan *pending-2FA state* (id user + flag remember) di session dan me-redirect ke halaman tantangan (challenge). `Auth::login` + `session()->regenerate()` baru dipanggil setelah kode TOTP (atau recovery code) valid.
2. **Admin tanpa 2FA terkonfirmasi**: user tetap di-`Auth::login` seperti biasa, tetapi middleware penegakan (enforcement) me-redirect **setiap** request ke halaman setup 2FA sampai dikonfirmasi — kecuali rute setup/confirm/logout itu sendiri. Ini adalah enrollment wajib.

Pengguna non-admin: alur login tidak berubah (byte-for-byte secara perilaku).

Stack yang dikunci: **Laravel 13 + Inertia + Vue 3 + PrimeVue (Aura) + Tailwind v4**, library TOTP `pragmarx/google2fa-laravel` (+ `pragmarx/google2fa`) dan QR `bacon/bacon-qr-code`. Secret & recovery codes dienkripsi at-rest dengan `Crypt`/`encrypt()` Laravel. UI berbahasa Indonesia, mengikuti pola Sakai + `FloatingConfigurator`.

---

## Tumpukan Teknologi (dikunci setelah desain disetujui)

- **Backend:** PHP 8 / Laravel 13.
- **TOTP:** `pragmarx/google2fa-laravel` (membawa `pragmarx/google2fa`). Dipakai hanya sebagai *library* (generate secret, verify dengan window) — **bukan** middleware/stateful session feature-nya; kita kelola state sendiri agar terintegrasi mulus dengan alur SIKEP.
- **QR code:** `bacon/bacon-qr-code` untuk render SVG dari URI `otpauth://`. SVG dipilih (bukan PNG) agar tidak butuh ekstensi `imagick`/`gd` dan aman dirender inline di Vue tanpa dependensi tambahan.
- **Enkripsi at-rest:** `Illuminate\Support\Facades\Crypt` (`encryptString`/`decryptString`) via accessor/mutator model.
- **Frontend:** Vue 3 + Inertia `useForm`, komponen PrimeVue auto-import (`unplugin-vue-components` + `PrimeVueResolver`, lihat `vite.config.js`) — **tidak ada import manual** komponen PrimeVue. `InputOtp` tersedia di PrimeVue 4.5.5 dan ikut ter-resolve otomatis.

Instalasi (cwd `f:\LARAVEL\remunisasi_v2`, PowerShell):

```
composer require pragmarx/google2fa-laravel
composer require bacon/bacon-qr-code
```

Jika jaringan tidak dapat menjangkau Packagist, **berhenti dan laporkan** — fitur bergantung pada paket ini dan tidak boleh di-stub.

---

## Konfigurasi

Tambah file `config/twofactor.php` baru (jangan menumpang di `config/sikep.php` yang khusus integrasi SIKEP):

```php
return [
    // Label issuer yang muncul di aplikasi authenticator.
    'issuer' => env('TWO_FACTOR_ISSUER', 'Remunisasi RSUD Bangil'),

    // Jumlah recovery code yang dibuat sekali enrollment.
    'recovery_code_count' => 8,

    // Toleransi window verifikasi TOTP (jumlah step 30 dtk ke tiap arah).
    // window=1 = step saat ini ±1 step (satu periode 30 dtk ke depan DAN ke belakang;
    // secara efektif sebuah kode bisa valid hingga ~90 dtk tergantung posisi dalam periode).
    // Trade-off clock-drift vs. jendela replay — 1 adalah nilai standar yang diterima.
    'window' => 1,
];
```

`APP_KEY` sudah ada (dipakai `Crypt`). Tidak ada env wajib baru; `TWO_FACTOR_ISSUER` opsional.

---

## Migration

File baru (JANGAN mengedit migration lama). Migration terakhir yang ada adalah `2026_10_07_100001_add_token_pegawai_to_users_table.php`, jadi gunakan timestamp sesudahnya:

**`database/migrations/2026_10_07_100002_add_two_factor_columns_to_users_table.php`**

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->text('two_factor_secret')->nullable()->after('token_pegawai');
        $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
        $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn([
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
        ]);
    });
}
```

Semua kolom **nullable** — pengguna non-admin dan admin yang belum enroll tetap valid dengan nilai null. Kolom teks (bukan string 255) karena menyimpan ciphertext hasil `Crypt::encryptString` yang panjang. Jalankan `php artisan migrate` setelah membuat file (disetujui).

**Catatan driver (resolusi temuan #5):** `->after('token_pegawai')` hanya kosmetik (mengatur posisi kolom) dan **hanya berlaku di MySQL/MariaDB**; di SQLite ia **diabaikan tanpa error**. Diverifikasi dari `phpunit.xml`: suite tes memakai `DB_CONNECTION=sqlite` + `:memory:`, jadi migrasi tetap jalan benar saat `RefreshDatabase`/`migrate` di tes — `->after()` tidak memengaruhi logika, hanya urutan kolom di MySQL produksi. Tidak ada perubahan logika yang diperlukan untuk mendukung kedua driver. Timestamp `100002 > 100001` memastikan kolom `token_pegawai` (dibuat `100001`) sudah ada saat `100002` berjalan.

---

## Perubahan Model `User`

File: `app/Models/User.php`.

1. **Fillable** — tambahkan tiga kolom. Catatan: secret & recovery codes **tidak** diset lewat mass-assignment biasa karena ada mutator enkripsi; tetap aman untuk dicantumkan karena mutator yang menentukan nilai akhir.

```php
#[Fillable([
    'sikep_user_id', 'username', 'nama', 'no_absen', 'token_pegawai', 'is_admin', 'last_sync_at',
    'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
])]
```

2. **Hidden** — tambahkan `two_factor_secret` dan `two_factor_recovery_codes` ke `#[Hidden]` sebagai pertahanan berlapis agar tidak pernah ikut terserialisasi ke JSON/Inertia secara tak sengaja (selain `remember_token` yang sudah ada).

3. **Casts** — tambahkan:

```php
'two_factor_confirmed_at' => 'datetime',
```

Secret dan recovery codes **tidak** memakai cast `encrypted`/`encrypted:array` bawaan, melainkan accessor/mutator eksplisit (poin 4) agar kontrol decrypt/`null`-handling jelas dan bisa diuji. (Alternatif cast `encrypted` dipertimbangkan; ditolak karena kita butuh pembacaan array yang toleran terhadap null dan helper `consumeRecoveryCode`, lebih jelas sebagai method eksplisit.)

4. **Accessor/mutator & helper** (semua di model):

```php
// Secret: simpan terenkripsi, baca terdekripsi.
protected function twoFactorSecret(): Attribute
{
    return Attribute::make(
        get: fn ($value) => $value === null ? null : Crypt::decryptString($value),
        set: fn ($value) => $value === null ? null : Crypt::encryptString($value),
    );
}

// Recovery codes: array<string> terenkripsi sebagai JSON.
protected function twoFactorRecoveryCodes(): Attribute
{
    return Attribute::make(
        get: fn ($value) => $value === null ? [] : json_decode(Crypt::decryptString($value), true),
        // Normalisasi array kosong → null (resolusi temuan NIT #6): "habis" (kode terakhir
        // dikonsumsi, $remaining = []) dan "belum pernah diisi" sama-sama tersimpan sebagai NULL,
        // bukan ciphertext "[]". Konsisten dengan jalur reset out-of-band yang menulis null.
        set: fn ($value) => empty($value) ? null : Crypt::encryptString(json_encode(array_values($value))),
    );
}

public function hasConfirmedTwoFactor(): bool
{
    return $this->two_factor_confirmed_at !== null;
}

public function requiresTwoFactor(): bool
{
    // Dipakai HANYA oleh middleware EnsureTwoFactor (re-resolve per-request), BUKAN
    // oleh LoginController::store() (lihat §Invarian Urutan). is_admin bersumber dari
    // flag lokal; RoleResolver degradasi anggun saat SIKEP down → 2FA tetap ditegakkan.
    return $this->roles()['is_admin'] === true;
}

// Konsumsi satu recovery code (single-use). Return true bila cocok & dihapus.
public function consumeRecoveryCode(string $code): bool
{
    $codes = $this->two_factor_recovery_codes; // array terdekripsi (format tersimpan XXXX-XXXX)

    // NORMALISASI TUNGGAL (satu-satunya tempat — resolusi temuan #2):
    // hapus SEMUA karakter non-alfanumerik (tanda hubung, spasi) lalu UPPER-CASE,
    // untuk input DAN untuk tiap kode tersimpan sebelum dibandingkan. Dengan begitu
    // "AB12-CD34", "ab12 cd34", dan "AB12CD34" semua cocok ke kode tersimpan "AB12-CD34".
    $normalize = static fn (string $v): string => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v));
    $input = $normalize($code);

    $matched = false;
    $remaining = [];
    foreach ($codes as $stored) {
        if (! $matched && hash_equals($normalize($stored), $input)) {
            $matched = true; // konsumsi satu kejadian; jangan masukkan ke sisa
            continue;
        }
        $remaining[] = $stored; // simpan sisa dalam format aslinya (XXXX-XXXX)
    }

    if (! $matched) {
        return false; // tidak ada yang cocok
    }

    $this->two_factor_recovery_codes = array_values($remaining); // mutator meng-enkripsi ulang
    $this->save();

    return true;
}
```

**Normalisasi dipaku di SATU tempat.** Pembandingan recovery code dinormalkan **hanya** di `consumeRecoveryCode` (bukan juga di controller) — strip non-alfanumerik + uppercase di kedua sisi `hash_equals`. Kode tetap **disimpan** dalam format `XXXX-XXXX` (dengan tanda hubung) agar tampilan sekali-pakai mudah dibaca; perbandingan mengabaikan tanda hubung/spasi sehingga user yang paste dengan atau tanpa pemisah tetap cocok. Ini menutup celah "recovery code sah ditolak" pada temuan #2.

`syncFromSikep` tetap tidak menyentuh kolom 2FA (sinkronisasi SIKEP tidak boleh mereset status 2FA). `roles()` tetap bekerja. Perlu `use Illuminate\Database\Eloquent\Casts\Attribute;` dan `use Illuminate\Support\Facades\Crypt;`.

**Invarian & kepemilikan:** enkripsi at-rest dimiliki sepenuhnya oleh layer model (mutator) — tidak ada layer lain yang menulis nilai mentah ke kolom ini. Pembandingan recovery code memakai `hash_equals` untuk mencegah timing attack dan memastikan single-use dengan menghapus elemen yang cocok dari set terenkripsi.

---

## `TwoFactorService`

File baru: `app/Services/TwoFactor/TwoFactorService.php`. Membungkus `pragmarx/google2fa`. SIKEP services tetap tidak disentuh.

Konstruktor meng-inject `PragmaRX\Google2FA\Google2FA`.

API:

```php
generateSecret(): string
// Google2FA->generateSecretKey(). Mengembalikan base32 secret plaintext
// (disimpan oleh pemanggil melalui mutator terenkripsi model).

buildOtpauthUri(string $secret, string $accountLabel): string
// otpauth://totp/{issuer}:{accountLabel}?secret=...&issuer={issuer}&algorithm=SHA1&digits=6&period=30
// issuer dari config('twofactor.issuer'); accountLabel = username/no_absen admin.

qrSvg(string $otpauthUri): string
// Render SVG via bacon/bacon-qr-code (ImageRenderer + SvgImageBackEnd + RendererStyle).
// Mengembalikan string markup <svg> siap di-render inline di frontend.

verify(string $secret, string $code): bool
// Google2FA->verifyKey($secret, $code, config('twofactor.window')).
// Guard defensif: bila kode kosong/non-6-digit, verify() mengembalikan FALSE secara DIAM
// (tanpa melempar, tanpa pesan). Ini pelindung lapis-dua, BUKAN pemilik pesan ke user.
// Pemilik pesan user-facing adalah validasi controller (lihat §Validasi Input / temuan #9):
// controller memvalidasi regex 6-digit lebih dulu dan melempar ValidationException dengan
// pesan "Kode verifikasi tidak valid." Jadi hanya ada SATU bentuk error ke pengguna
// (dari controller); service tak pernah menghasilkan bentuk error kedua.

generateRecoveryCodes(): array
// config('twofactor.recovery_code_count') kode acak, unik, format TETAP: XXXX-XXXX
// (9 karakter: dua gugus 4 karakter dipisah tanda hubung).
// Alfabet kustom EKSPLISIT tanpa karakter ambigu (resolusi temuan #6):
//   const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // tanpa 0,1,O,I
// Tiap karakter dipilih via random_int(0, strlen(ALPHABET)-1) — BUKAN Str::random,
// karena Str::random tidak menjamin pengecualian 0/1/O/I. Delapan karakter → format
// "XXXX-XXXX". Dedup set untuk menjamin unik (regen bila tabrakan, sangat jarang).
// Kode DISIMPAN & DITAMPILKAN dalam format ini; consumeRecoveryCode menormalkan
// (strip non-alfanumerik + strtoupper) sebelum hash_equals (lihat temuan #2).
// Dikembalikan sebagai array plaintext untuk ditampilkan sekali dan disimpan terenkripsi
// oleh pemanggil.
```

`qrSvg` memakai `BaconQrCode\Renderer\ImageRenderer`, `BaconQrCode\Renderer\Image\SvgImageBackEnd`, `BaconQrCode\Renderer\RendererStyle\RendererStyle`, dan `BaconQrCode\Writer`.

**Testability:** `verify`, `generateSecret`, `generateRecoveryCodes`, dan `buildOtpauthUri` murni/deterministik-terhadap-input → unit test langsung. `verify` dapat diuji dengan membangkitkan kode via `Google2FA->getCurrentOtp($secret)`.

---

## Perubahan `LoginController`

File: `app/Http/Controllers/Auth/LoginController.php`. Hanya method `store()` dan `destroy()` yang berubah; `create()` tetap.

Sisipkan logika 2FA **di antara** `User::syncFromSikep($sikepUser)` dan `Auth::login(...)` yang ada. Potongan konseptual (perhatikan **urutan baris yang persis mengikuti kode aktual** — lihat Invarian Urutan di bawah):

```php
// --- Baris EXISTING, tidak diubah ---
$roles = $this->roleResolver->resolve($sikepUser->id); // dipanggil SEBELUM sync
if ($roles['roles'] === []) { /* tolak akun tanpa peran — existing */ }
$user = User::syncFromSikep($sikepUser);               // updateOrCreate; tidak sentuh is_admin/2FA
// --- Akhir baris existing ---

// SISIPAN 2FA. WAJIB memakai $roles['is_admin'] yang SUDAH di-resolve di baris existing
// di atas. JANGAN resolve ulang dan JANGAN memakai $user->requiresTwoFactor() di sini
// (lihat Invarian Urutan — requiresTwoFactor() me-query roles() lagi; dipakai hanya di middleware).
$isAdmin = $roles['is_admin'];

if ($isAdmin && $user->hasConfirmedTwoFactor()) {
    // JANGAN login. Simpan pending-2FA state, redirect ke challenge.
    $request->session()->put('2fa.pending_user_id', $user->id);
    $request->session()->put('2fa.remember', $request->boolean('remember'));
    // Simpan juga intended URL kalau sudah ada (opsional; redirect()->intended tetap jalan).
    return redirect()->route('two-factor.challenge');
}

// Non-admin, ATAU admin yang belum konfirmasi 2FA → login seperti biasa.
Auth::login($user, $request->boolean('remember'));
$request->session()->regenerate();

return redirect()->intended(route('dashboard'));
```

- Blok `try/catch (QueryException)`, cek `$sikepUser === null` dengan pesan generik, resolve roles, dan penolakan akun tanpa peran **tetap persis** seperti sekarang.
- Admin yang **belum** konfirmasi 2FA sengaja tetap di-`Auth::login` — penegakan enrollment ditangani middleware (lihat di bawah), bukan di controller, agar berlaku untuk **setiap** request bukan hanya saat login.

### Invarian Urutan `resolve()` → `syncFromSikep()` (resolusi temuan HIGH #1)

Temuan review benar: di kode aktual `$roles = $this->roleResolver->resolve($sikepUser->id)` dipanggil **sebelum** `$user = User::syncFromSikep($sikepUser)`, dan `RoleResolver::resolve()` menentukan `is_admin` dari query row lokal `users` (`where('sikep_user_id', …)->where('is_admin', true)->exists()`). Keputusan "apakah user ini wajib 2FA" karena itu bergantung pada urutan baca. Desain memaku invarian berikut agar tidak ada ruang bypass:

1. **Satu sumber kebenaran untuk `is_admin` di `store()`.** Keputusan cabang 2FA **wajib** memakai `$roles['is_admin']` yang sudah di-resolve pada baris existing (sebelum `syncFromSikep`). Implementor **tidak boleh** memanggil `$user->requiresTwoFactor()` di `LoginController::store()`. Alasan: `requiresTwoFactor()` memanggil `roles()` yang me-resolve ulang via `RoleResolver` — menduplikasi query, dan lebih buruk, membuka celah bila dipanggil di titik yang berbeda dari pembacaan `is_admin` yang sudah dipakai untuk menolak akun tanpa peran. Dengan memakai satu `$roles` yang sama untuk (a) penolakan akun tanpa peran dan (b) cabang 2FA, kedua keputusan dijamin konsisten.

2. **`syncFromSikep` tidak mengubah `is_admin` maupun kolom 2FA.** Diverifikasi terhadap `app/Models/User.php`: `syncFromSikep` adalah `updateOrCreate` yang hanya menulis `username`, `nama`, `no_absen`, `token_pegawai`, `last_sync_at` — ia **tidak** menyentuh `is_admin`, `two_factor_secret`, `two_factor_recovery_codes`, maupun `two_factor_confirmed_at`. Maka instance `$user` yang dikembalikan memuat `two_factor_confirmed_at` apa adanya dari DB (untuk row yang sudah ada) atau `null` (untuk row yang baru dibuat). `$user->hasConfirmedTwoFactor()` karena itu konsisten dengan `$roles['is_admin']` yang dibaca sesaat sebelumnya — keduanya mengacu pada row DB yang sama.

3. **Perilaku first-login yang dijamin aman (bukan bypass).** `is_admin` adalah flag lokal yang **hanya** di-set operator (bukan oleh `syncFromSikep`). Dua kasus:
   - **Row belum pernah ada saat `resolve()` berjalan** → `exists()` → `false` → `is_admin=false`. Tapi karena `is_admin` belum pernah di-set `true` (row-nya saja belum ada), user ini memang **bukan** admin pada login tersebut; ia login sebagai non-admin biasa. Ini bukan bypass: tidak ada admin yang kehilangan faktor kedua, karena belum ada admin. Begitu operator menandai `is_admin=true` di row yang sudah ter-create, login **berikutnya** meresolusi `is_admin=true` dan jalur 2FA aktif.
   - **Row sudah ada dengan `is_admin=true`** (kasus normal admin) → `resolve()` meresolusi `is_admin=true` sebelum sync, `syncFromSikep` memperbarui kolom non-2FA, `$user->hasConfirmedTwoFactor()` dibaca dari row yang sama → cabang challenge/enrollment aktif dengan benar. Tidak ada jendela di mana admin terkonfirmasi bisa `Auth::login` langsung.

   Konsekuensi yang dinyatakan eksplisit: **tidak ada kondisi di mana user ber-`is_admin=true` dengan 2FA terkonfirmasi dapat login tanpa melewati challenge.** Satu-satunya "kelonggaran" adalah login paling pertama sebuah akun sebelum row-nya pernah dibuat — dan pada saat itu akun tersebut belum admin menurut definisi sistem.

4. **Asersi tes feature wajib (bukti anti-bypass):** tambahkan kasus "admin yang row user-nya baru saja dibuat (first login sesudah di-set `is_admin=true`) dengan `two_factor_confirmed_at` terisi → tetap diarahkan ke `two-factor.challenge`, bukan `Auth::login` langsung". Tes ini membuktikan konsistensi `$roles['is_admin']` ↔ `hasConfirmedTwoFactor()` pada jalur first-login (lihat §Rencana Verifikasi).

`destroy()` ditambah pembersihan state pending supaya tidak ada sisa:

```php
public function destroy(Request $request): RedirectResponse
{
    Auth::logout();
    $request->session()->forget(['2fa.pending_user_id', '2fa.remember']);
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
}
```

(`invalidate()` sudah mengosongkan session, tetapi `forget` eksplisit dicantumkan sebagai dokumentasi niat dan untuk jalur logout yang mungkin tidak invalidate penuh di masa depan.)

---

## `TwoFactorController`

File baru: `app/Http/Controllers/Auth/TwoFactorController.php`. Meng-inject `TwoFactorService`. Lima method.

### 1. `challenge()` — tampilkan halaman tantangan (GET `two-factor.challenge`)

- Guard (urutan tegas — resolusi temuan #5):
  1. Jika user **sudah terautentikasi penuh** (`Auth::check()` true) → redirect ke `dashboard`. Rute ini di luar grup `guest`, jadi tidak ada auto-bounce; kasus admin yang sudah login lalu mengetik `/two-factor/challenge` manual harus ditangani eksplisit agar tidak menyentuh/mengganggu sesi hidupnya. Redirect ke dashboard, **bukan** `login` (mereka sudah login).
  2. Jika `session('2fa.pending_user_id')` kosong (dan tidak login) → redirect ke `login`. Satu-satunya tiket masuk sah ke challenge adalah pending state yang ditulis oleh `LoginController::store()`.
- Render `Inertia::render('Auth/TwoFactorChallenge')`. Tidak mengirim data sensitif.

**Invarian penulisan pending-id (dinyatakan eksplisit — temuan #5):** `2fa.pending_user_id` **hanya** ditulis di satu tempat, yaitu `LoginController::store()` **setelah** password SIKEP terverifikasi benar oleh `SikepAuthService` dan peran ter-resolve. Tidak ada rute/kode lain yang menyetelnya. `session()->regenerate()` **ditunda** sampai faktor kedua lolos (di `verifyChallenge`), bukan saat password benar — ini dua hal berbeda: menunda regenerate menjaga pending-id tetap di session yang sama hingga challenge selesai, dan karena `Auth::login` belum dipanggil, tidak ada sesi terautentikasi yang bisa di-fiksasi (first-factor sudah lewat, second-factor belum). Integritas session Laravel (ditandatangani) berarti nilai pending-id tidak bisa dipalsukan/di-replay oleh klien untuk melcompat first-factor.

### 2. `verifyChallenge(Request)` — proses tantangan (POST `two-factor.challenge.verify`)

- Guard pending state sama seperti di atas; jika hilang → `ValidationException` / redirect login.
- Ambil `$user = User::find(session('2fa.pending_user_id'))`. Bila null → bersihkan state, redirect login.
- **Guard integritas secret (resolusi temuan #7):** setelah `$user` didapat, bila `$user->two_factor_secret === null` **atau** `! $user->hasConfirmedTwoFactor()` (mis. status di-reset out-of-band tepat setelah pending ditulis, atau race), perlakukan pending sebagai sesi tidak valid: `forget(['2fa.pending_user_id','2fa.remember'])` lalu `redirect()->route('login')`. Dengan ini `TwoFactorService::verify(null, ...)` tidak pernah terpanggil pada secret null di jalur TOTP, dan jalur recovery tidak dijalankan untuk user yang bukan admin-terkonfirmasi.
- **Validasi input — mode DIPAKU, strategi "deteksi dari field terisi" DIHAPUS (resolusi temuan MEDIUM #2).** Hanya ada SATU field `code` tunggal; `mode` wajib dan percabangan verifikasi mengikuti `mode` yang sudah tervalidasi (bukan menebak dari field mana yang terisi). Validasi `code` **bersyarat** terhadap `mode`, lalu verifikasi dibungkus try/catch `DecryptException` (jalur pre-login):
  ```php
  use Illuminate\Validation\Rule;
  use Illuminate\Contracts\Encryption\DecryptException;

  $data = $request->validate([
      'mode' => 'required|in:totp,recovery',
      'code' => ['required', 'string', Rule::when(
          $request->input('mode') === 'totp',
          'regex:/^\d{6}$/',                             // TOTP: tepat 6 digit
          'regex:/^[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}$/',    // recovery: XXXX-XXXX, hyphen opsional
      )],
  ]);

  try {
      // Percabangan STRICTLY mengikuti mode tervalidasi — tidak ada inferensi dari field.
      // Membaca two_factor_secret / consumeRecoveryCode memicu decrypt di accessor.
      $ok = $data['mode'] === 'totp'
          ? $this->service->verify($user->two_factor_secret, $data['code'])
          : $user->consumeRecoveryCode($data['code']);
  } catch (DecryptException $e) {
      // --- Penanganan DecryptException PRE-login dipaku (resolusi temuan MEDIUM #3). ---
      // User BELUM Auth::login di sini, jadi JANGAN redirect ke two-factor.setup (butuh auth);
      // jatuhkan ke login dengan pesan generik.
      report($e); // level error — konfigurasi 2FA korup (APP_KEY dirotasi / ciphertext rusak)
      $user->forceFill([
          'two_factor_secret'         => null,
          'two_factor_recovery_codes' => null,
          'two_factor_confirmed_at'   => null,
      ])->save();
      $request->session()->forget(['2fa.pending_user_id', '2fa.remember']);

      return redirect()->route('login')
          ->withErrors(['login' => 'Konfigurasi 2FA Anda perlu diatur ulang. Hubungi administrator.']);
  }
  ```
  Dengan `mode` wajib dan tervalidasi, tidak ada lagi ambiguitas "jika mode=totp tapi field recovery terisi" — hanya `$data['code']` yang dipakai, dan cabang ditentukan `$data['mode']` saja. Frontend menyetel `form.mode` saat menukar InputOtp ↔ InputText recovery (lihat §Frontend), sehingga field↔mode selalu terikat satu-ke-satu. `forceFill` dipakai agar penulisan `null` menembus mutator (`null → null`), bukan guarded assignment; reset pada jalur pre-login aman karena hanya butuh instance `$user` dari `pending_user_id`, bukan sesi login.
- **TOTP:** `TwoFactorService::verify($user->two_factor_secret, $data['code'])` (di dalam try di atas).
- **Recovery:** `$user->consumeRecoveryCode($data['code'])` (di dalam try di atas).
- Bila valid:
  ```php
  Auth::login($user, (bool) session('2fa.remember'));
  $request->session()->forget(['2fa.pending_user_id', '2fa.remember']);
  $request->session()->regenerate();
  return redirect()->intended(route('dashboard'));
  ```
- Bila tidak valid: `throw ValidationException::withMessages(['code' => 'Kode verifikasi tidak valid.'])`. Pesan sama untuk TOTP salah maupun recovery salah (tidak membocorkan mode mana yang gagal).
- **Rate limiting:** lihat bagian khusus di bawah.

### 3. `setup()` — tampilkan halaman enrollment (GET `two-factor.setup`)

- **Guard anti-kebocoran-secret DIPAKU di baris paling atas (resolusi temuan HIGH #1).** Admin yang **sudah** terkonfirmasi tidak boleh pernah membuka ulang halaman ini, karena `setup()` akan membangun QR/secret dari `two_factor_secret` aktif dan membocorkan faktor kedua yang masih berlaku. Guard adalah kode konkret, bukan prosa:
  ```php
  public function setup(Request $request)
  {
      $user = $request->user();

      // Hanya admin yang WAJIB 2FA dan BELUM mengonfirmasi yang boleh melihat
      // halaman enrollment. User null, non-admin, atau admin terkonfirmasi
      // di-redirect ke dashboard SEBELUM secret apa pun dibaca/dibangkitkan —
      // sehingga secret aktif tidak pernah di-ekspos ulang.
      if ($user === null || ! $user->requiresTwoFactor() || $user->hasConfirmedTwoFactor()) {
          return redirect()->route('dashboard');
      }

      // Draft secret: hanya dibangkitkan untuk admin UNCONFIRMED.
      // Membaca two_factor_secret bisa melempar DecryptException bila draft korup
      // (APP_KEY dirotasi) — tangkap, buang draft, bangkitkan ulang (POST-login path, temuan #3).
      try {
          $secret = $user->two_factor_secret;
      } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
          report($e);
          $user->forceFill([
              'two_factor_secret'         => null,
              'two_factor_recovery_codes' => null,
              'two_factor_confirmed_at'   => null,
          ])->save();
          $secret = null;
      }

      if ($secret === null) {
          $user->two_factor_secret = $this->service->generateSecret(); // mutator meng-enkripsi
          $user->save();
          $secret = $user->two_factor_secret;
      }

      $otpauthUri = $this->service->buildOtpauthUri($secret, $user->username);
      $svg        = $this->service->qrSvg($otpauthUri);

      return Inertia::render('Auth/TwoFactorSetup', [
          'qrSvg'  => $svg,
          'secret' => $secret, // base32 plaintext, HANYA untuk admin unconfirmed yang enroll
      ]);
  }
  ```
  Karena guard menolak admin terkonfirmasi di baris pertama (`hasConfirmedTwoFactor()`), `two_factor_secret` yang dibaca/dibangkitkan sesudahnya **selalu** milik admin yang sedang enroll — bukan secret aktif siapa pun. Ini menutup celah "re-view/re-expose active TOTP secret" pada temuan HIGH #1.
  - **Pengecualian terkendali:** QR SVG dan secret base32 memang dikirim ke frontend — ini wajib untuk enrollment. Ini satu-satunya titik secret TOTP boleh menyeberang ke klien, dan hanya untuk admin pemilik akun yang **belum** terkonfirmasi. Recovery codes TIDAK dikirim di sini (baru dibuat saat confirm).

**Exemption middleware tidak boleh meloloskan admin terkonfirmasi ke `two-factor.setup` (resolusi temuan HIGH #1).** `EnsureTwoFactor` saat ini meloloskan admin terkonfirmasi untuk **semua** rute (`if ($user->hasConfirmedTwoFactor()) return $next($request);`), termasuk `GET two-factor.setup`. Guard controller di atas sudah menutup kebocoran, tetapi middleware diperkuat sebagai pertahanan berlapis agar admin terkonfirmasi **tidak pernah mencapai** `setup()` sama sekali: lihat §Middleware Penegakan — cek khusus di-tambahkan sehingga admin terkonfirmasi yang mengetik `/two-factor/setup` di-redirect ke `dashboard`, bukan diloloskan `$next`.

### 4. `confirmSetup(Request)` — konfirmasi enrollment (POST `two-factor.setup.confirm`)

- Validasi `code` (`required|string|regex:/^\d{6}$/`).
- **Penanganan `DecryptException` POST-login dipaku (resolusi temuan MEDIUM #3).** Berbeda dari `verifyChallenge`, di sini user **sudah** `Auth::login` (admin unconfirmed yang sedang enroll), jadi fallback yang benar adalah reset kolom lalu **redirect `two-factor.setup`** untuk enroll ulang (bukan `login`):
  ```php
  use Illuminate\Contracts\Encryption\DecryptException;

  try {
      $ok = $this->service->verify($user->two_factor_secret, $data['code']);
  } catch (DecryptException $e) {
      report($e);
      $user->forceFill([
          'two_factor_secret'         => null,
          'two_factor_recovery_codes' => null,
          'two_factor_confirmed_at'   => null,
      ])->save();

      return redirect()->route('two-factor.setup'); // draft rusak → generate ulang di setup()
  }
  ```
- Bila `$ok` false → `ValidationException(['code' => 'Kode verifikasi tidak valid.'])`.
- Bila berhasil:
  ```php
  $recovery = $this->service->generateRecoveryCodes();
  $user->two_factor_recovery_codes = $recovery; // mutator enkripsi
  $user->two_factor_confirmed_at = now();
  $user->save();
  ```
- Tampilkan recovery codes **sekali** sebagai respons `Inertia::render` langsung (BUKAN `redirect()->with(...)`):
  ```php
  return Inertia::render('Auth/TwoFactorSetup', [
      'recoveryCodes' => $recovery, // plaintext, HANYA pada respons ini
      'justConfirmed' => true,
  ]);
  ```
  - **Mengapa `Inertia::render`, bukan `redirect()->with('recoveryCodes', ...)`:** `HandleInertiaRequests::share()` (file `app/Http/Middleware/HandleInertiaRequests.php`, sudah diverifikasi) hanya mengekspos empat key flash **hardcoded** (`success`, `error`, `warning`, `info`) dan tidak meneruskan key flash sembarang. Jadi `->with('recoveryCodes', ...)` akan tersimpan di session tetapi **tidak pernah** terserialisasi ke props Inertia — halaman akan merender kosong dan admin tak pernah melihat kodenya (ini temuan HIGH #1 pada review). Dengan mengembalikan `Inertia::render` secara eksplisit, `recoveryCodes` menjadi prop khusus respons POST ini saja; request/refresh berikutnya adalah GET `two-factor.setup` biasa yang **tidak** mengirim `recoveryCodes` (prop default `null`), sehingga kode otomatis hilang setelah navigasi/refresh.
  - **Keputusan:** opsi ini dipilih di atas alternatif "menambah key `recoveryCodes` ke `share()`" karena tidak membocorkan jalur-sekali-pakai ini ke props global setiap request dan tidak menyentuh `HandleInertiaRequests`. `HandleInertiaRequests::share()` **tidak** diubah untuk fitur ini.
  - **Tradeoff refresh/re-POST:** karena ini respons POST, menekan refresh browser memicu dialog re-submit. Mitigasi: halaman menandai fase recovery dari prop `recoveryCodes`/`justConfirmed` dan menyediakan tombol eksplisit "Selesai, lanjut ke dashboard" (`router.visit(route('dashboard'))`); admin diarahkan keluar dari respons POST lewat tombol itu, bukan refresh. Status 2FA sudah tersimpan sebelum render, jadi re-POST yang tak sengaja hanya menghasilkan `ValidationException` (kode sudah dipakai / field kosong) tanpa mengubah data.
  - **Pengecualian terkendali:** ini satu-satunya momen recovery codes plaintext dikirim ke frontend.
- Setelah confirm, `requiresTwoFactor()` + `hasConfirmedTwoFactor()` → true, middleware tidak lagi memaksa redirect; user bebas memakai aplikasi.

### 5. `regenerateRecoveryCodes(Request)` — opsional (POST `two-factor.recovery-codes.regenerate`)

- Hanya admin yang sudah konfirmasi. Generate set baru, simpan terenkripsi, lalu kembalikan `Inertia::render('Auth/TwoFactorSetup', ['recoveryCodes' => $recovery, 'justConfirmed' => true])` (pola yang sama persis dengan `confirmSetup` — satu respons, hilang setelah navigasi). Berguna bila user kehabisan/khawatir codes bocor. Ditempatkan di belakang middleware `auth` + penegakan 2FA biasa (user sudah login penuh).

### Jalur pemulihan saat recovery codes habis DAN authenticator hilang (resolusi temuan #6)

Ini adalah skenario lockout nyata: admin yang kehilangan perangkat authenticator **dan** sudah memakai habis semua recovery code tidak bisa melewati challenge, dan `regenerateRecoveryCodes` membutuhkan sesi terautentikasi penuh (yang justru butuh melewati challenge). Tidak ada jalan keluar self-service — ini **disengaja** demi keamanan (jalur self-reset tanpa faktor apa pun = bypass 2FA).

**Jalur reset out-of-band yang ditetapkan (bukan lockout implisit):** reset status 2FA dilakukan oleh administrator sistem/DBA secara manual dengan menyetel ketiga kolom ke `null` untuk user bersangkutan, sehingga admin jatuh kembali ke jalur **enrollment wajib** (bukan langsung masuk — tetap harus enroll ulang + konfirmasi sebelum memakai app):

- Via tinker (dari `f:\LARAVEL\remunisasi_v2`):
  ```
  php artisan tinker
  >>> $u = \App\Models\User::where('username', 'USERNAME_ADMIN')->firstOrFail();
  >>> $u->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
  ```
  (`forceFill` + penulisan `null` memicu mutator yang mengembalikan `null`, jadi kolom benar-benar kosong.)
- Setelah reset, login SIKEP berikutnya → `hasConfirmedTwoFactor()` false → `EnsureTwoFactor` memaksa admin ke halaman setup untuk enroll ulang. Tidak ada akses ke app sebelum konfirmasi ulang.

**Siapa yang boleh:** hanya pemegang akses server/DB (operator sistem), bukan lewat UI aplikasi. Mencegah penyalahgunaan sebagai bypass. Catatan: skenario "state 2FA korup karena `APP_KEY` dirotasi" di tabel Penanganan Error memakai mekanisme reset-ke-`null` yang sama, hanya dipicu otomatis saat `DecryptException`.

Menambahkan jalur reset self-service via email/SMS **di luar scope** (butuh kanal verifikasi tepercaya kedua yang belum ada di sistem) dan sengaja tidak ditambahkan; reset out-of-band di atas adalah escape path resmi.

---

## Routing

File: `routes/web.php`.

**Rute challenge** harus bisa diakses dengan **hanya** pending-session (bukan `guest`, bukan `auth` penuh). Diletakkan di luar kedua grup, dengan guard pending di controller:

```php
// 2FA Challenge — dapat diakses saat pending-2FA (belum Auth::login).
Route::get('/two-factor/challenge', [TwoFactorController::class, 'challenge'])
    ->name('two-factor.challenge');
Route::post('/two-factor/challenge', [TwoFactorController::class, 'verifyChallenge'])
    ->middleware('throttle:two-factor')
    ->name('two-factor.challenge.verify');
```

Catatan: rute ini **tidak** boleh di dalam grup `guest`, karena `guest` middleware me-redirect user yang (nanti) sudah login; dan tidak di `auth` karena saat challenge user belum login. Guard sesungguhnya = keberadaan `2fa.pending_user_id` di session (dicek di controller).

**Rute setup & confirm** di dalam grup `auth` (user admin sudah login tapi dipaksa enroll). Grup `auth` yang ada diubah untuk juga memasang `two-factor` (lihat §Middleware Penegakan untuk alasan pemilihan titik ini alih-alih append ke web group). Beri nama rute agar middleware penegakan bisa mengecualikannya:

```php
// SEBELUM: Route::middleware('auth')->group(function () {
// SESUDAH:
Route::middleware(['auth', 'two-factor'])->group(function () {
    // ... semua rute yang sudah ada TIDAK berubah (dashboard, logout, admin, katimker, kabid, dst) ...

    Route::get('/two-factor/setup', [TwoFactorController::class, 'setup'])
        ->name('two-factor.setup');
    Route::post('/two-factor/setup', [TwoFactorController::class, 'confirmSetup'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.setup.confirm');
    Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
        ->middleware('throttle:two-factor') // resolusi temuan MEDIUM #4 — limiter sama dgn challenge/confirm
        ->name('two-factor.recovery-codes.regenerate');
});
```

Satu-satunya perubahan pada grup existing adalah menambahkan `'two-factor'` ke array middleware grup `auth`; definisi tiap rute di dalamnya tidak disentuh. `two-factor` di level grup menjamin `$request->route()` sudah ter-resolve saat `EnsureTwoFactor` berjalan (lihat §Middleware Penegakan), sehingga pengecualian `logout`/`two-factor.setup`/`two-factor.setup.confirm` selalu akurat dan admin tak pernah terkunci dari logout.

---

## Middleware Penegakan — `EnsureTwoFactor`

File baru: `app/Http/Middleware/EnsureTwoFactor.php`. Mengikuti gaya `EnsureRole` (sederhana, langsung). Tujuan: admin terautentikasi yang **belum** konfirmasi 2FA diarahkan ke setup pada **setiap** request kecuali rute yang dikecualikan.

```php
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();

    // Tidak login → biarkan auth middleware lain yang menangani.
    if ($user === null) {
        return $next($request);
    }

    // Hanya admin yang terdampak. Non-admin lolos tanpa biaya tambahan.
    if (! $user->requiresTwoFactor()) {
        return $next($request);
    }

    // Admin sudah konfirmasi → lolos, KECUALI halaman enrollment itu sendiri.
    // Admin terkonfirmasi yang mencoba membuka two-factor.setup / confirm di-redirect
    // ke dashboard agar tidak pernah mencapai setup() dan mengekspos ulang secret aktif
    // (pertahanan berlapis atas guard controller — resolusi temuan HIGH #1).
    if ($user->hasConfirmedTwoFactor()) {
        $enrollmentRoutes = ['two-factor.setup', 'two-factor.setup.confirm'];
        if (in_array($request->route()?->getName(), $enrollmentRoutes, true)) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }

    // Admin BELUM konfirmasi → kecualikan rute setup/confirm/logout, selain itu paksa ke setup.
    $allowed = ['two-factor.setup', 'two-factor.setup.confirm', 'logout'];
    if (in_array($request->route()?->getName(), $allowed, true)) {
        return $next($request);
    }

    return redirect()->route('two-factor.setup');
}
```

### Titik pemasangan — aliased pada grup `auth`, BUKAN di-append ke web group

**Keputusan (resolusi temuan HIGH #2):** `EnsureTwoFactor` dipasang sebagai middleware **ber-alias** (`'two-factor'`) dan diterapkan pada **grup rute `auth`** di `routes/web.php`, **bukan** di-append ke web group di `bootstrap/app.php`.

Alasan konkret, diverifikasi terhadap aplikasi ini:

- **Route binding dijamin sudah ter-resolve.** Jika di-append ke web group, tidak terjamin `$request->route()` sudah terikat saat middleware itu berjalan; bila `null`, operator null-safe `$request->route()?->getName()` menghasilkan `null`, cek `in_array` gagal, dan admin unconfirmed di-redirect ke setup pada **setiap** request **termasuk `logout`** → terkunci total (melanggar Invarian Keamanan 3). Dengan memasang middleware pada **grup rute** (seperti `role:admin` yang sudah terbukti bekerja sebagai middleware level-rute di `routes/web.php`), middleware berjalan **setelah** route di-match, sehingga `$request->route()->getName()` selalu terisi dan daftar pengecualian (`two-factor.setup`, `two-factor.setup.confirm`, `logout`) selalu akurat. `logout` berada di dalam grup `auth` yang sama, jadi exempt-nya terjamin.
- **Pola yang sudah ada.** `EnsureRole` sudah dipakai sebagai middleware ber-alias level-rute (`->middleware('role:admin')`) dan berjalan dengan route binding ter-resolve. `EnsureTwoFactor` mengikuti pola yang persis sama, hanya diterapkan di level grup `auth` (satu baris) agar mencakup semua rute terautentikasi.
- **Cakupan menyeluruh tanpa append.** Karena semua rute aplikasi yang perlu diproteksi sudah berada di dalam satu grup `auth`, menerapkan `two-factor` pada grup itu mencakup seluruhnya dalam satu titik. Rute challenge sengaja berada di LUAR grup `auth` (lihat Routing), jadi tidak terkena enforcement — benar, karena saat challenge user memang belum login.

**Registrasi alias** di `bootstrap/app.php` (hanya menambah satu alias; web `append` TIDAK diubah):

```php
$middleware->web(append: [
    \App\Http\Middleware\HandleInertiaRequests::class,
    \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
]);

$middleware->alias([
    'role' => \App\Http\Middleware\EnsureRole::class,
    'two-factor' => \App\Http\Middleware\EnsureTwoFactor::class,
]);
```

**Penerapan** pada grup `auth` di `routes/web.php` (lihat bagian Routing untuk diff lengkap): grup berubah dari `Route::middleware('auth')` menjadi `Route::middleware(['auth', 'two-factor'])`. Urutan `auth` lebih dulu memastikan user sudah terautentikasi (atau di-redirect ke login) sebelum `EnsureTwoFactor` membaca `$request->user()`.

**Catatan fail-safe:** karena enforcement kini melekat pada grup `auth` (bukan tiap rute individual), setiap rute baru yang ditambahkan **di dalam grup `auth`** otomatis ikut terproteksi — sama amannya dengan append, tanpa risiko route-binding `null`. Satu-satunya kewajiban implementor: rute terautentikasi baru harus diletakkan di dalam grup `auth` ini (yang memang sudah menjadi konvensi file).

**Catatan performa (temuan NIT #8 — deferral sadar):** pada request ke rute admin, `roles()` di-resolve **dua kali** per request: sekali oleh `EnsureTwoFactor::requiresTwoFactor()` dan sekali lagi oleh `EnsureRole` (`role:admin`), masing-masing memicu `RoleResolver::resolve()` yang query SIKEP + lokal. Ini **diterima secara sadar** dan **di luar scope** fitur ini: `roles()` sudah di-query berulang pada pola existing, dan optimisasi (memoisasi per-request di model `User`, mis. properti cache yang di-reset tiap request) adalah perbaikan terpisah yang menyentuh `RoleResolver`/`User` di luar lingkup 2FA. Dicatat eksplisit agar double-resolve bukan kejutan, melainkan keputusan tertunda.

---

## Shared Inertia Props

File: `app/Http/Middleware/HandleInertiaRequests.php`. Tambahkan dua boolean non-sensitif ke `auth.user` agar frontend bisa menampilkan status/menu (mis. banner "aktifkan 2FA"):

```php
'two_factor_enabled' => $u->hasConfirmedTwoFactor(),
'needs_two_factor_setup' => $roles['is_admin'] && ! $u->hasConfirmedTwoFactor(),
```

**Tidak pernah** mengirim `two_factor_secret` atau `two_factor_recovery_codes` ke frontend lewat share (keduanya juga sudah di `#[Hidden]`). Hanya boolean turunan.

---

## Rate Limiting

**Titik registrasi (dipaku — resolusi temuan #4):** definisikan limiter bernama `two-factor` di `App\Providers\AppServiceProvider::boot()`. Diverifikasi: `app/Providers/AppServiceProvider.php` ada, `boot()` ada dan saat ini kosong, dan provider ini terdaftar/dimuat default oleh Laravel (`bootstrap/providers.php`). Juga diverifikasi via pencarian: **tidak ada** `RateLimiter::for` di mana pun saat ini, jadi tidak ada "provider yang ada" lain untuk ditumpangi — alternatif itu dihapus. Registrasi harus berjalan sebelum route resolution; `boot()` provider memenuhi syarat itu, sehingga `throttle:two-factor` pada rute tidak akan melempar "limiter tidak terdaftar".

Tambahkan import di `AppServiceProvider`:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
```

Isi `boot()`:

```php
public function boot(): void
{
    RateLimiter::for('two-factor', function (Request $request) {
        $key = $request->session()->get('2fa.pending_user_id')
            ?? optional($request->user())->id
            ?? $request->ip();

        return [Limit::perMinute(5)->by((string) $key)];
    });
}
```

Terapkan ke `two-factor.challenge.verify`, `two-factor.setup.confirm`, dan `two-factor.recovery-codes.regenerate` (resolusi temuan #4 — endpoint regenerate menghasilkan material sensitif, memakai limiter yang sama) lewat `->middleware('throttle:two-factor')`.

5 percobaan per menit per akun (fallback ke IP) menyeimbangkan keamanan brute-force vs. user yang salah ketik. Saat limit terlampaui, Laravel mengembalikan 429 otomatis; frontend menampilkan pesan generik dari respons. Rate-limit per-akun (bukan hanya IP) penting karena satu IP rumah sakit bisa dipakai banyak user.

---

## Penanganan Error (per operasi yang dapat gagal)

| Operasi | Kondisi gagal | Recoverable? | Yang diterima pemanggil | Logging |
|---|---|---|---|---|
| `composer require` paket | Jaringan/Packagist tak terjangkau | Fatal (setup) | — | STOP & lapor ke user (bukan runtime) |
| SIKEP `attempt` (existing) | `QueryException` koneksi SIKEP | Recoverable | `ValidationException` key `login`: "Integrasi SIKEP tidak tersedia..." | `report($e)` (existing, tak diubah) |
| Verifikasi TOTP challenge | Kode salah/kadaluarsa/format bukan 6 digit | Recoverable | `ValidationException` key `code`: "Kode verifikasi tidak valid." | Tidak di-log (event normal); gagal berulang dibatasi throttle |
| Recovery code challenge | Kode tidak cocok di set | Recoverable | Sama: key `code` "Kode verifikasi tidak valid." | Tidak di-log |
| Rate limit challenge/confirm | >5/menit | Recoverable (tunggu) | HTTP 429 + pesan generik | Otomatis Laravel |
| `Crypt::decryptString` pada secret/recovery — **PRE-login** (`verifyChallenge`) | `DecryptException` (APP_KEY berubah / data korup) | Fatal untuk 2FA user tsb | `report($e)`; `forceFill` ketiga kolom → null + save; `forget` pending state; `redirect()->route('login')->withErrors(['login'=>'Konfigurasi 2FA Anda perlu diatur ulang. Hubungi administrator.'])` (user belum login → tidak boleh ke setup) | `report($e)` level error |
| `Crypt::decryptString` pada secret/recovery — **POST-login** (`setup`/`confirmSetup`) | `DecryptException` (APP_KEY berubah / data korup) | Fatal untuk 2FA user tsb | `report($e)`; reset ketiga kolom → null + save; `redirect()->route('two-factor.setup')` agar re-enroll (user sudah login) | `report($e)` level error |
| `User::find(pending_user_id)` | User terhapus di tengah pending | Recoverable | Bersihkan pending state, redirect `login` | `report` level warning (jarang) |
| Pending user secret null / 2FA di-reset saat pending | `two_factor_secret===null` atau `!hasConfirmedTwoFactor()` di `verifyChallenge` (reset out-of-band / race) | Recoverable | Bersihkan pending state, redirect `login` (sesi tak valid); `verify(null,...)` tak pernah dipanggil | Tidak di-log (sangat jarang) |
| Pending state hilang saat challenge | Session expired / akses langsung | Recoverable | Redirect `login` | Tidak di-log (ekspektasi normal) |
| `confirmSetup` kode salah | Kode salah | Recoverable | `ValidationException` key `code` | Tidak di-log |

Untuk `DecryptException`: controller membungkus pembacaan secret dengan try/catch; bila gagal decrypt (mis. `APP_KEY` dirotasi), status 2FA user dianggap rusak → `report()` sebagai error, reset `two_factor_secret`/`two_factor_recovery_codes`/`two_factor_confirmed_at` ke null (lewat mutator) sehingga user jatuh ke jalur enrollment ulang alih-alih terkunci selamanya. Keputusan ini sadar-tradeoff: lebih baik memaksa re-enroll daripada mengunci admin total.

---

## Validasi Input (per input eksternal)

| Input | Rute | Aturan | Perilaku saat gagal |
|---|---|---|---|
| `login` | POST /login | required, string | existing — tak diubah |
| `password` | POST /login | required, string | existing — tak diubah |
| `remember` | POST /login | boolean (via `$request->boolean`) | existing |
| `mode` | POST challenge | **required, in:`totp`,`recovery`** (bukan optional/default — resolusi temuan #2) | `ValidationException` key `mode` (tidak ada fallback diam) |
| `code` (TOTP) | POST challenge/confirm | required, string, `regex:/^\d{6}$/` (bersyarat saat `mode=totp`) | `ValidationException` key `code` "Kode verifikasi tidak valid." |
| `code` (recovery) | POST challenge (`mode=recovery`) | required, string, `regex:/^[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}$/` (bersyarat saat `mode=recovery`) | format jelas-salah → key `code` "Kode verifikasi tidak valid."; format valid tapi tak cocok di set → key `code` pesan generik sama |

**Kepemilikan pesan vs. guard (resolusi temuan #9):** validasi di **controller** adalah pemilik tunggal pesan user-facing — ia memvalidasi `regex:/^\d{6}$/` (TOTP) / `regex:/^[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}$/` (recovery) dan melempar `ValidationException` dengan pesan `code` "Kode verifikasi tidak valid." sebelum memanggil service. Guard di `TwoFactorService::verify()` (menolak non-6-digit) hanya **mengembalikan `false` secara diam** sebagai pertahanan berlapis — tidak melempar dan tidak menghasilkan bentuk/pesan error kedua. Dengan begitu implementor tidak mengembalikan dua shape error berbeda untuk input tak valid.

Validasi 6-digit (`regex:/^\d{6}$/`) dilakukan sebelum memanggil `verify` untuk menolak input jelas-salah tanpa menyentuh library. Recovery code memakai pola eksplisit `regex:/^[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}$/` (bukan lagi `max:32` yang terlalu longgar — resolusi temuan #2): tanda hubung opsional, dua gugus 4 karakter alfanumerik, sehingga input yang jelas-salah ditolak sama agresifnya dengan jalur TOTP. Input yang lolos format dinormalkan **sekali** di `consumeRecoveryCode` (strip non-alfanumerik + `strtoupper` di kedua sisi) lalu dibandingkan via `hash_equals` dengan set tersimpan — lihat §Perubahan Model `User`.

---

## Frontend (Sakai, Indonesia, pertahankan tombol tema)

Komponen PrimeVue auto-import (tanpa import manual). Keduanya memakai `FloatingConfigurator` (tombol tema) seperti `Login.vue`.

### `resources/js/Pages/Auth/TwoFactorChallenge.vue`

- `defineOptions({ layout: GuestLayout })` — **GuestLayout** dipilih karena user **belum** login penuh (pending-2FA). AppLayout butuh `auth.user` + sidebar; tidak sesuai untuk state pre-login. Konsisten dengan `Login.vue`.
- Struktur meniru panel kanan `Login.vue`: logo, judul "Verifikasi Dua Langkah", subteks "Masukkan kode 6 digit dari aplikasi authenticator Anda.", dibungkus `.card`.
- Input kode: `<InputOtp :length="6" integerOnly v-model="form.code" />` (tersedia di PrimeVue 4.5.5). **Prop `integerOnly` DIVERIFIKASI valid (resolusi temuan NIT #5):** diperiksa langsung di `node_modules/primevue/inputotp/index.d.ts` pada versi terpasang — `InputOtpProps` mendeklarasikan `integerOnly?: boolean | undefined` (`@defaultValue false`, "specifies that an input field is integer-only"). Jadi `integerOnly` **bukan** atribut DOM yang gagal diam; ia benar-benar membatasi input ke angka. Fallback bila tim memutuskan tidak memakai InputOtp: `<InputText inputmode="numeric" maxlength="6">`. Terlepas dari prop klien ini, **pengaman sesungguhnya tetap validasi server** `regex:/^\d{6}$/` di `verifyChallenge`/`confirmSetup`. Desain memilih **InputOtp** karena UX 6-kotak lebih jelas dan native.
- Toggle "Gunakan kode pemulihan" (`<Button text>` / link) yang menukar `form.mode` ke `recovery` dan menampilkan `<InputText>` untuk kode pemulihan menggantikan InputOtp.
- Submit: `form.post(route('two-factor.challenge.verify'))`, tombol `<Button label="Verifikasi" :loading="form.processing" class="w-full">`.
- Error: `form.errors.code` via `<small class="text-red-500">`; pesan 429/umum bisa lewat `<Message severity="error">` seperti `Login.vue`.
- Tombol tema `<FloatingConfigurator />` di-mount **di template halaman ini sendiri** (bukan dari layout), persis seperti `Login.vue`, agar "pertahankan tombol tema" terpenuhi dan dark mode benar.

### `resources/js/Pages/Auth/TwoFactorSetup.vue`

- **Pilihan layout: `GuestLayout`.** Alasan (diminta dijelaskan): meski user admin unconfirmed secara teknis sudah `Auth::login`, mereka **dikurung** oleh `EnsureTwoFactor` dan tidak boleh menavigasi aplikasi. Memakai AppLayout akan menampilkan sidebar/menu penuh yang mengundang klik ke rute lain — padahal semua akan di-redirect balik, menciptakan UX membingungkan dan kesan "setengah masuk". GuestLayout menyajikan halaman enrollment fokus tanpa navigasi, sejalan dengan sifat "wajib selesaikan dulu". Setelah confirm, request berikutnya memakai AppLayout normal.
- **Tombol/kontrol "Keluar" eksplisit (wajib, bukan sekadar prosa — resolusi temuan #3).** Karena GuestLayout tidak punya chrome aplikasi (tak ada menu/logout bawaan), halaman HARUS menyediakan elemen logout sendiri agar admin yang sedang terkurung tetap punya jalan keluar (ini juga menutup skenario loop pada temuan #2). Spesifikasi elemen konkret, di pojok/atas kartu enrollment:
  ```vue
  <script setup>
  import { router } from '@inertiajs/vue3';
  function keluar() {
      router.post(route('logout')); // Inertia menyertakan CSRF token otomatis via XSRF-TOKEN
  }
  </script>
  <!-- template -->
  <Button label="Keluar" icon="pi pi-sign-out" severity="secondary" text @click="keluar" />
  ```
  `router.post` (Inertia) menangani CSRF melalui cookie `XSRF-TOKEN` (header `X-XSRF-TOKEN`) sehingga tidak perlu `@csrf` manual. Rute `logout` ada di daftar pengecualian `EnsureTwoFactor`, jadi POST ini tidak di-redirect balik ke setup.
- **Tombol tema level-halaman (resolusi temuan #3).** Kedua halaman baru (`TwoFactorChallenge.vue` dan `TwoFactorSetup.vue`) me-mount `<FloatingConfigurator />` **sendiri di template halaman**, persis seperti `Login.vue` (sudah diverifikasi: `Login.vue` menata `defineOptions({ layout: GuestLayout })` dan merender `<FloatingConfigurator />` di dalam template-nya, bukan mengandalkan layout). Dengan begitu persyaratan "pertahankan tombol tema" terpenuhi secara eksplisit dan dark mode (`.app-dark`) + token tema (`var(--p-*)`, `text-surface-900 dark:text-surface-0`, `text-muted-color`, `text-primary`) ikut benar tanpa bergantung pada chrome layout. (Tradeoff pemilihan GuestLayout: lihat kontrol "Keluar" di atas.)
- **Satu komponen melayani DUA respons berbeda — kondisi fase dipaku eksplisit (resolusi temuan #3).** Halaman yang sama di-render untuk (a) GET `two-factor.setup` yang mengirim `qrSvg` + `secret` **tanpa** `recoveryCodes`, dan (b) respons POST `confirmSetup`/`regenerateRecoveryCodes` yang mengirim `recoveryCodes` + `justConfirmed=true` **tanpa** `qrSvg`/`secret`. Agar tidak ada state campur atau akses prop `undefined`:
  - **`defineProps` dengan default aman:**
    ```js
    defineProps({
        qrSvg: { type: String, default: null },
        secret: { type: String, default: null },
        recoveryCodes: { type: Array, default: () => [] },
        justConfirmed: { type: Boolean, default: false },
    });
    ```
  - **Kondisi fase (computed), saling-eksklusif:**
    - Fase **recovery** aktif ⇔ `justConfirmed === true` (ekuivalen: `recoveryCodes.length > 0`). Hanya saat ini daftar recovery codes dirender.
    - Fase **enrollment** aktif ⇔ `!justConfirmed && !!qrSvg`. Hanya saat ini blok QR (`v-html="qrSvg"`) dan kunci manual dirender. Guard `!!qrSvg` memastikan `v-html` tak pernah menerima `null`/`undefined`.
  - Template membungkus tiap fase dengan `v-if` pada computed di atas, sehingga respons confirm **tidak** merender blok QR dan GET setup **tidak** merender blok recovery codes.
- Dua fase dalam satu halaman:
  1. **Enrollment** (`v-if="faseEnrollment"`): tampilkan QR via `v-html="qrSvg"` (SVG dari server) di dalam `.card`, kunci rahasia manual (`props.secret`) ditampilkan dalam blok mono + tombol salin (`<Button icon="pi pi-copy" />` + toast "Kunci disalin"), lalu `<InputOtp :length="6" v-model="form.code" />` + `<Button label="Aktifkan 2FA" :loading>` yang `form.post(route('two-factor.setup.confirm'))`.
  2. **Recovery codes (sekali)** (`v-if="faseRecovery"`): fase ini aktif bila `justConfirmed === true` (prop halaman dari respons `confirmSetup`/`regenerateRecoveryCodes` via `Inertia::render`, BUKAN flash). Tampilkan daftar kode (`recoveryCodes`) dalam grid mono, peringatan menonjol (`<Message severity="warn">` "Simpan kode ini sekarang. Kode tidak akan ditampilkan lagi."), tombol **Salin semua** dan **Unduh .txt** (blob di sisi klien), dan tombol "Selesai, lanjut ke dashboard" (`router.visit(route('dashboard'))`). Karena `recoveryCodes`/`justConfirmed` hanya ada pada respons POST tersebut, request GET berikutnya (`two-factor.setup`) tidak mengirimnya → `faseRecovery` false → fase ini otomatis tidak tampil lagi. Jangan membaca `recoveryCodes` dari `usePage().props.flash` — key itu tidak ada di `share()`.
- Teks UI Indonesia; token tema (`text-surface-900 dark:text-surface-0`, `text-muted-color`), dark mode via `dark:`.
- `<FloatingConfigurator />` di-mount di template halaman (lihat poin "Tombol tema level-halaman" di atas).

Catatan: `v-html` dipakai hanya untuk SVG QR yang **dibangkitkan server dari data kita sendiri** (bukan input user), sehingga tidak membuka XSS. Dinyatakan eksplisit agar reviewer tahu ini disengaja dan aman.

---

## Invarian Keamanan (dijamin)

1. **Tanpa bypass.** Admin dengan 2FA terkonfirmasi **tidak** di-`Auth::login` saat password benar; hanya pending-session yang dibuat. Semua rute aplikasi di belakang `auth`, dan pending-session **bukan** status login — sehingga `auth` middleware memblokir akses sampai challenge lolos. `Auth::login` baru dipanggil di `verifyChallenge` setelah TOTP/recovery valid.
2. **Recovery code single-use.** `consumeRecoveryCode` menghapus kode yang cocok dari set terenkripsi dan menyimpannya; kode yang sama tidak bisa dipakai dua kali.
3. **Enforcement admin tak terlewat, logout selalu terjangkau.** `EnsureTwoFactor` dipasang sebagai middleware ber-alias (`'two-factor'`) pada grup rute `auth` (bukan di-append ke web group), sehingga `$request->route()` dijamin sudah ter-resolve dan daftar pengecualian akurat. Admin unconfirmed di-redirect ke setup pada setiap request kecuali `two-factor.setup`, `two-factor.setup.confirm`, dan `logout` — `logout` tidak akan pernah terjebak loop redirect, dan halaman setup menyediakan tombol "Keluar" eksplisit sebagai jalan keluar.
4. **Enkripsi at-rest & tak bocor.** `two_factor_secret` dan `two_factor_recovery_codes` disimpan via `Crypt` dan ada di `#[Hidden]`; tidak pernah dikirim ke frontend kecuali: (a) QR SVG + secret base32 saat halaman setup (wajib enrollment, hanya pemilik akun), dan (b) recovery codes plaintext sekali pada respons confirm-setup. Shared props hanya mengekspos boolean turunan.
5. **Jalur SIKEP tak berubah.** Verifikasi password SIKEP, blocklist master password, pesan generik "Kredensial yang diberikan tidak cocok", penanganan `QueryException`, dan penolakan akun tanpa peran semua tetap di `SikepAuthService`/`LoginController` tanpa perubahan perilaku. Non-admin login persis seperti sebelumnya.
6. **Penegakan 2FA tidak bergantung ketersediaan SIKEP (resolusi temuan #4).** Penegakan 2FA admin bergantung pada flag lokal `is_admin` yang diresolusi `RoleResolver` — dan `RoleResolver::resolve()` menangani `QueryException` SIKEP dengan degradasi anggun (`$jabatan = []`) sambil tetap meresolusi `is_admin` dari row lokal `users`. Konsekuensinya, bila integrasi SIKEP sedang tidak tersedia, admin tetap ter-identifikasi admin dan `EnsureTwoFactor` tetap memaksa challenge/enrollment. 2FA tidak dapat dilewati dengan menjatuhkan koneksi SIKEP.
7. **Keputusan `is_admin` konsisten satu-sumber di `store()`.** `LoginController::store()` memakai `$roles['is_admin']` yang sama untuk penolakan akun tanpa peran dan untuk cabang 2FA; `requiresTwoFactor()` tidak dipakai di controller (lihat §Invarian Urutan). Tidak ada jendela di mana admin ber-2FA-terkonfirmasi login tanpa challenge.

---

## Rencana Verifikasi

Dijalankan dari `f:\LARAVEL\remunisasi_v2` (PowerShell).

1. **Instalasi paket** (jika jaringan gagal → STOP & lapor):
   `composer require pragmarx/google2fa-laravel` lalu `composer require bacon/bacon-qr-code`.
2. **Migrasi:** `php artisan migrate` → verifikasi tiga kolom muncul di `users`.
3. **Test terfokus (headless, verifiable):**
   - Unit `TwoFactorServiceTest`: `verify()` menerima OTP valid (dibangkitkan via `getCurrentOtp`) dan menolak kode salah; `generateRecoveryCodes()` menghasilkan N kode unik.
   - Unit/Model `consumeRecoveryCode`: kode valid dikonsumsi (hilang dari set, return true), kode sama kedua kali return false.
   - Feature `LoginController`: admin 2FA-terkonfirmasi dengan password benar → **tidak** login, redirect ke `two-factor.challenge`, session punya `2fa.pending_user_id`; non-admin → login langsung ke dashboard; admin unconfirmed → login tapi `EnsureTwoFactor` redirect ke `two-factor.setup`.
   - Feature **anti-bypass first-login (temuan #1):** admin yang row user-nya **baru dibuat** pada login tersebut (first login sesudah operator menandai `is_admin=true`) dan sudah `two_factor_confirmed_at` terisi → **tetap** diarahkan ke `two-factor.challenge`, bukan `Auth::login` langsung. Membuktikan konsistensi `$roles['is_admin']` ↔ `hasConfirmedTwoFactor()` pada jalur first-login dan bahwa `store()` tidak memakai `requiresTwoFactor()`.
   - Feature `consumeRecoveryCode` normalisasi (temuan #2): recovery code yang di-paste **tanpa** tanda hubung (`AB12CD34`) dan dengan huruf kecil tetap cocok dengan kode tersimpan `AB12-CD34`; input dengan format jelas-salah ditolak oleh validasi controller sebelum menyentuh model.
   - Feature `verifyChallenge`: TOTP valid → `Auth::check()` true + redirect dashboard; TOTP salah → validasi gagal, tetap tak login; recovery code valid → login + kode terkonsumsi (tes ulang pakai kode sama → gagal).
   - Feature `EnsureTwoFactor`: admin unconfirmed mengakses rute admin → redirect ke setup; mengakses `two-factor.setup` → lolos; **mengakses `logout` (POST) → TIDAK di-redirect ke setup, berhasil logout** (menutup temuan #2 secara otomatis).
   - Feature `confirmSetup`: kode valid → respons memuat prop `recoveryCodes` (array, len = config) dan `justConfirmed=true`; `two_factor_confirmed_at` terisi; GET `two-factor.setup` berikutnya TIDAK memuat `recoveryCodes` (prop default `[]`) dan **tidak** merender blok QR pada respons confirm; sebaliknya GET `two-factor.setup` **tidak** merender blok recovery codes (asersi fase — temuan #3).
   - Feature `verifyChallenge` guard secret-null (temuan #7): bila `two_factor_secret` user pending di-set null di tengah pending, POST challenge → pending state dibersihkan, redirect `login`, dan tidak ada error dari pemanggilan `verify(null,...)`.
   - Feature `challenge()`: user terautentikasi penuh membuka `two-factor.challenge` → redirect `dashboard` (bukan `login`), sesi hidup tidak terganggu (temuan #5).
4. **Build frontend:** `npm run build` harus lolos (dua halaman Vue baru + auto-import PrimeVue).
5. **Route list:** `php artisan route:list` menampilkan `two-factor.challenge`, `two-factor.challenge.verify`, `two-factor.setup`, `two-factor.setup.confirm`, `two-factor.recovery-codes.regenerate`.

**Verifiable headless:** semua test di atas, migrate, route:list, build.
**Manual browser QA (tak bisa headless):** scan QR dengan Google Authenticator/Authy sungguhan dan enrollment end-to-end; render light/dark kedua halaman baru; tampilan copy/download recovery codes; alih InputOtp ↔ input recovery code.

---

## Ringkasan Keputusan Desain

- **Library:** `pragmarx/google2fa-laravel` sebagai library TOTP murni (bukan middleware stateful-nya), `bacon/bacon-qr-code` untuk QR **SVG** (tanpa gd/imagick). Fortify sengaja tidak dipakai.
- **State pending-2FA via session, bukan `Auth::login` dini** → jaminan no-bypass bertumpu pada `auth` middleware yang sudah ada.
- **Enrollment wajib via middleware `EnsureTwoFactor` ber-alias pada grup rute `auth`** (bukan di-append ke web group), agar route binding terjamin ter-resolve, `logout` tidak terjebak, dan penegakan tetap berlaku ke setiap request terautentikasi.
- **Enkripsi at-rest via accessor/mutator eksplisit + `hash_equals`**, kolom di `#[Hidden]`, hanya boolean turunan yang dibagikan ke frontend.
- **GuestLayout untuk kedua halaman auth 2FA** — challenge karena pre-login; setup karena admin terkurung dan tidak boleh menavigasi app (plus tombol "Keluar" eksplisit).
- **InputOtp** untuk entri 6 digit (tersedia di PrimeVue 4.5.5, auto-import), toggle ke recovery code.
- **Rate limit 5/menit per akun** pada verifikasi challenge & confirm.
- **Config baru `config/twofactor.php`** (issuer, jumlah recovery code, window) terpisah dari `config/sikep.php`.
- **Migration baru** `2026_10_07_100002_add_two_factor_columns_to_users_table.php`, tiga kolom nullable, `down()` men-drop ketiganya.
- **DecryptException → reset status 2FA + re-enroll** alih-alih mengunci admin.

---

## Tanggapan atas Temuan Design Review

### Putaran terbaru (`design-review.json` — 1 HIGH, 3 MEDIUM, 2 NIT)

Menanggapi review terbaru `.agents/tasks/admin-2fa/design-review.{md,json}` (verdict CHANGES_REQUESTED). Keenam temuan putaran ini **ditangani** — tidak ada yang di-backlog atau diabaikan — dan tak satu pun bertentangan dengan requirement/keputusan owner (2FA wajib untuk admin, jalur SIKEP non-admin tak berubah).

- **#1 [HIGH] — `setup()` dapat mengekspos ulang TOTP secret aktif ke admin terkonfirmasi.** DITANGANI. Dipaku guard konkret (kode, bukan prosa) di baris paling atas `setup()`: `if ($user === null || ! $user->requiresTwoFactor() || $user->hasConfirmedTwoFactor()) return redirect()->route('dashboard');` — admin terkonfirmasi di-redirect **sebelum** secret apa pun dibaca/dibangkitkan, sehingga secret aktif tidak pernah di-ekspos ulang. Sebagai pertahanan berlapis, `EnsureTwoFactor` diperkuat: cabang admin-terkonfirmasi kini me-redirect `two-factor.setup`/`two-factor.setup.confirm` ke `dashboard` alih-alih meloloskan `$next`, sehingga admin terkonfirmasi tidak pernah mencapai `setup()`. Prop respons `setup()` diubah ke variabel `$secret` lokal yang hanya terisi untuk admin unconfirmed.
- **#2 [MEDIUM] — ambiguitas mode challenge (`in:totp,recovery` ATAU deteksi dari field terisi).** DITANGANI. Strategi "deteksi dari field terisi" **dihapus seluruhnya**. `mode` dipaku `required|in:totp,recovery`; field tunggal `code` divalidasi **bersyarat** via `Rule::when($mode==='totp', 'regex:/^\d{6}$/', 'regex:/^[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}$/')`. Percabangan verifikasi mengikuti `$data['mode']` yang sudah tervalidasi secara STRICT (TOTP → `service->verify`, recovery → `consumeRecoveryCode`). Tabel Validasi Input diperbarui: `mode` kini required (bukan optional/default `totp`).
- **#3 [MEDIUM] — `DecryptException` tak dipaku di seluruh titik baca secret.** DITANGANI di ketiga titik dengan respons per-konteks: (a) `verifyChallenge` (PRE-login, belum `Auth::login`) → `report($e)`, `forceFill` ketiga kolom null + save, `forget` pending state, `redirect()->route('login')->withErrors(['login'=>'Konfigurasi 2FA Anda perlu diatur ulang. Hubungi administrator.'])`; (b) `confirmSetup` (POST-login) → `report`, reset ketiga kolom null, `redirect()->route('two-factor.setup')` untuk re-enroll; (c) `setup()` membaca draft dalam try/catch, draft korup dibuang + dibangkitkan ulang. Tabel Penanganan Error kini punya **dua baris** `DecryptException` yang membedakan PRE-login vs POST-login.
- **#4 [MEDIUM] — rute `two-factor.recovery-codes.regenerate` tanpa rate-limit.** DITANGANI. Ditambahkan `->middleware('throttle:two-factor')` (limiter sama persis dengan challenge-verify & setup-confirm) pada rute regenerate di `routes/web.php`; §Rate Limiting diperbarui untuk menyebut ketiga rute.
- **#5 [NIT] — prop `integerOnly` pada `<InputOtp>` tak diverifikasi untuk PrimeVue 4.5.5.** DITANGANI. Diverifikasi langsung di `node_modules/primevue/inputotp/index.d.ts` versi terpasang: `InputOtpProps` mendeklarasikan `integerOnly?: boolean | undefined` (default `false`) — prop valid, bukan atribut DOM gagal-diam. Design mencatat verifikasi ini, menyebut fallback `<InputText inputmode="numeric" maxlength="6">`, dan menegaskan pengaman sesungguhnya tetap validasi server `regex:/^\d{6}$/`.
- **#6 [NIT] — mutator recovery codes menyimpan `"[]"` terenkripsi (bukan null) saat array kosong.** DITANGANI. Mutator diganti `empty($value) ? null : Crypt::encryptString(...)` sehingga "habis" (kode terakhir dikonsumsi) dan "belum pernah diisi" sama-sama tersimpan sebagai `null`, konsisten dengan jalur reset out-of-band.

### Putaran sebelumnya (revisi ke-2 & ke-3 — ditutup)

Temuan dari putaran-putaran sebelumnya (2 HIGH + 4 MEDIUM + 4 NIT pada revisi ke-2; 1 HIGH + 2 MEDIUM + 5 NIT pada revisi ke-3) telah ditutup dan diakui reviewer. Ringkasan yang masih relevan:

- **Ordering `resolve()` vs `syncFromSikep()`** — ditutup via §Invarian Urutan: `$roles['is_admin']` satu-sumber di `store()`, `requiresTwoFactor()` dilarang di controller, `syncFromSikep` tak menyentuh kolom 2FA, plus tes anti-bypass first-login.
- **Validasi recovery + normalisasi hyphen** — pola `regex:/^[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}$/`, normalisasi dipaku satu tempat di `consumeRecoveryCode`.
- **Fase prop `qrSvg`/`secret` vs `recoveryCodes`** — `defineProps` default aman + fase saling-eksklusif (`justConfirmed` vs `!justConfirmed && !!qrSvg`).
- **Enforcement saat SIKEP down** — §Invarian Keamanan poin 6 (degradasi anggun `RoleResolver`).
- **`->after()` di SQLite** — catatan driver di §Migration (kosmetik, diabaikan diam di SQLite).
- **Alfabet recovery tanpa 0/1/O/I** — `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` + `random_int`.
- **Guard `two_factor_secret` null di `verifyChallenge`** — pending dibersihkan + redirect `login`.
- **Komentar window TOTP** — diperjelas (±1 step, efektif ~90 dtk).
