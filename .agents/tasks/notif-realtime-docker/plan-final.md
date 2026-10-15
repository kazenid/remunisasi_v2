# Rencana Implementasi Final: Notifikasi Realtime (SSE) di Docker

Sumber: `findings.md` (bukti), `notif-realtime-sse/{design,plan,verification}.md`, dan pembacaan ulang semua file yang diubah.
Konvensi: Bahasa Indonesia untuk komentar/doc, TIDAK ada worktree (kerja langsung di `f:\LARAVEL\remunisasi_v2`), JANGAN `git commit`, JANGAN membaca/menampilkan nilai `.env`. Shell: PowerShell (Windows). Docker 29.6 / Compose v5.3 tersedia lokal; stack dev `remunisasi_v2` (port 8085) sedang berjalan. Stack lain di mesin ini (`pqms-*`, `sikep_v2-*`, `simrs_*`) JANGAN disentuh.

## 0. Keputusan desain (ringkas, dengan alasan)

| # | Keputusan | Alasan |
|---|---|---|
| D1 | Callback `subscribe` jadi `function (string $message, string $chan)`. Log `warning` bila `RedisException::getPrevious() !== null`. | `PhpRedisConnection::subscribe()` (vendor `:458-463`) memanggil `$callback($message, $channel)`. Error di dalam callback muncul sebagai `previous`; jangan ditelan diam-diam. |
| D2 | **Celah "pesan hilang" ditutup dengan catch-up berbasis DB**, bukan sekadar `OPT_READ_TIMEOUT` lebih panjang. Tiap siklus (sebelum `subscribe`) controller menjalankan satu query terindeks ke tabel `notifications` untuk baris milik user dengan `created_at > cursor`, mengirimnya (dedup by id), lalu `subscribe`. Cursor awal = `Last-Event-ID` (id notifikasi terakhir yang diterima browser, dikirim otomatis oleh `EventSource` saat reconnect) bila valid dan milik user itu; jika tidak, `now()`. Setiap event SSE membawa `id: <uuid>`. | Pub/Sub Redis tidak menyimpan pesan; tiap `RedisException` (tick) membuang socket dan membuat koneksi baru, jadi pesan yang di-publish di jeda itu hilang. Memperpanjang read-timeout hanya memperkecil frekuensi celah, tidak menghilangkannya, dan memperlambat deteksi abort. DB adalah sumber kebenaran, jadi catch-up membuat pengiriman menjadi *at-least-once* (kehilangan maksimal tertunda satu tick, bukan permanen). Catch-up juga menutup reconnect browser (restart nginx/php-fpm, batas 3600 dtk) lewat `Last-Event-ID`. |
| D3 | Read-timeout subscribe (tick) dinaikkan dari 1 dtk ke **5 dtk** (`private const TICK_SECONDS = 5`). | Dengan catch-up, 1 dtk berarti 1 query DB + 1 koneksi TCP baru per dtk per tab terbuka (boros). 5 dtk: jalur normal tetap instan (callback dipanggil begitu pesan tiba), pesan yang jatuh di celah pulih ≤5 dtk. Deteksi abort klien tetap bergantung heartbeat (default 20 dtk), tidak berubah. |
| D4 | Dedup di server memakai `$seen` (array id, maks 100) karena pesan yang sama bisa datang dari Pub/Sub DAN catch-up. Cursor catch-up maju ke `created_at` baris terakhir hasil query. Format cursor ke query: string `Y-m-d H:i:s.u` (presisi mikrodetik, cocok dengan `DatabaseNotification::$dateFormat` dan kolom `timestamp(6)`). | Query builder memotong `DateTimeInterface` ke detik; string manual menjaga presisi. Karena `>`, kekeliruan hanya bisa menghasilkan duplikat (diserap `$seen` + dedup frontend), tidak pernah kehilangan. |
| D5 | Bentuk sinyal disatukan di `NotificationService::signalFor(DatabaseNotification $n): array` (dipakai `notify()` untuk publish dan controller untuk catch-up). | Hindari drift antara payload Pub/Sub dan payload catch-up. |
| D6 | Controller diberi *seam* `protected`: `connect()`, `prepareOutput()`, `sse()`, `shouldContinue()`. | `\Redis::OPT_READ_TIMEOUT` fatal bila ekstensi `redis` tak ada (php lokal Windows tidak punya), dan `ob_end_flush`/`ob_flush` merusak buffer PHPUnit. Seam membuat controller dapat diuji dengan koneksi palsu tanpa Redis. Perilaku produksi tidak berubah. |
| D7 | Kegagalan koneksi Redis (awal maupun rebuild) = log `warning` lalu **akhiri stream** (bukan 500/uncaught). Kirim `retry: 5000`. | Browser (EventSource) otomatis reconnect dan `onerror` menyalakan polling. Mencegah badai reconnect 3 dtk dan stack trace di log. |
| D8 | Sumber heartbeat dipindah ke `config('app.sse_heartbeat')` (`env('SSE_HEARTBEAT_SECONDS', 20)`), BUKAN lagi `database.redis.notifications.read_timeout`. Blok `redis.notifications` diberi `'timeout' => 2` (connect) dan `'read_timeout' => 3` (publish). | `read_timeout` dibaca Laravel (`PhpRedisConnector.php:123-124,178`) sebagai read timeout klien phpredis, jadi nilai 20 membuat `publish()` bisa menahan request hingga 20 dtk. `publish()` berjalan sinkron di request submit/review, jadi harus terbatas (≤5 dtk total). Stream menimpa sendiri dengan `OPT_READ_TIMEOUT`. |
| D9 | Frontend: `pollOnce()` pada `onopen`; safety poll 5 menit; timer "stuck CONNECTING" 10 dtk; reconnect manual bila `readyState === CLOSED`; dedup by id sebelum efek samping. Tidak ada library/test-framework JS baru. | `onopen` mematikan polling, sehingga stream yang "terbuka tapi tak mengirim" membuat user harus reload. Safety poll murah (1 request per 5 menit per tab terlihat). `EventSource` TIDAK reconnect otomatis bila respons non-200/ bukan `text/event-stream` (mis. 502 saat deploy) → perlu reopen manual. Proyek tidak punya framework tes JS (`package.json` hanya vite), jadi tes frontend = `npm run build` + verifikasi manual/e2e. |
| D10 | `nginx` TETAP membangun asetnya sendiri di Dockerfile-nya (dua build Vite), tetapi keduanya memakai stage identik + stage `ziggy`. Opsi "nginx menyalin `public/build` dari image app" TIDAK dipilih. | Butuh `additional_contexts` (Compose ≥2.17) atau tag image yang harus dibangun lebih dulu; versi Compose di server produksi tidak diketahui. Build identik (input sama, `npm ci`) menghasilkan hash aset sama. Risiko drift diterima dan didokumentasikan. |
| D11 | Stage Ziggy: `FROM composer:2 AS ziggy` → `composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs` (hanya dipakai menyalin `vendor/tightenco/ziggy`). Stage Node menyalin `vendor/tightenco/ziggy` dari stage itu. | `resources/js/app.js` mengimpor `'../../vendor/tightenco/ziggy'`. Dengan `.dockerignore` yang mengecualikan `vendor/`, stage Node tak punya paket itu. Layer ter-cache selama `composer.json/lock` tak berubah. |
| D12 | `.dockerignore` mengecualikan `vendor`, `node_modules`, `.env`, `public/hot`, `public/build`, dll., tetapi MEMPERTAHANKAN kerangka `storage/**/.gitignore` dan `bootstrap/cache/.gitignore`; Dockerfile prod juga `mkdir -p` kerangka storage dan `rm -f public/hot`. | `COPY . .` + `chown/chmod storage bootstrap/cache` butuh direktori ada; named volume `sakai_storage` diinisialisasi dari isi image. Defense in depth untuk `public/hot`. `docker/` TIDAK boleh diabaikan (`Dockerfile.dev` melakukan `COPY docker/php/php-fpm.conf`). |
| D13 | Service `queue` diberi `user: www-data`. Dokumentasikan `chown` satu kali untuk volume lama dan `exec -u www-data` di README. | Minimal dan aman: `laravel.log` buatan root di volume bersama memblokir tulis php-fpm (www-data). Image sudah `chown www-data`. |
| D14 | `.env` tidak lagi masuk image → `php artisan key:generate` di dalam container tidak berguna. README diubah: generate key di host / `docker compose run --rm app php artisan key:generate --show`, tempel ke `.env` host. Env runtime tetap dari `env_file`. | Konsekuensi langsung `.dockerignore`. |

## 1. Rencana per file

### 1.1 `app/Http/Controllers/NotificationStreamController.php` (tulis ulang isi, perilaku publik sama)

Kerangka (ikuti gaya dan komentar Indonesia berkas yang ada):

```php
use App\Models\DatabaseNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Str;

class NotificationStreamController extends Controller
{
    private const TICK_SECONDS = 5;     // OPT_READ_TIMEOUT subscribe (D3)
    private const CATCHUP_LIMIT = 50;
    private const SEEN_MAX = 100;

    public function stream(Request $request): StreamedResponse
    {
        $user = $request->user();
        $userId = $user->id;
        $sikepUserId = (int) $user->sikep_user_id;
        // Cursor SINKRON sebelum stream dimulai (memakai koneksi DB request ini).
        $cursor = $this->resolveCursor($request->header('Last-Event-ID'), $userId);

        $response = new StreamedResponse(function () use ($sikepUserId, $userId, $cursor) {
            $this->prepareOutput();                 // set_time_limit(0) + ob_end_flush loop
            $this->sse('retry: 5000');
            $this->sse(': connected');

            $channel = "notif.user.{$sikepUserId}";
            $hb = max(1, (int) config('app.sse_heartbeat', 20));
            $last = time();
            $seen = [];

            $emit = function (string $json, ?string $id) use (&$seen) {
                if ($id !== null) {
                    if (isset($seen[$id])) return;           // dedup Pub/Sub vs catch-up
                    $seen[$id] = true;
                    if (count($seen) > self::SEEN_MAX) $seen = array_slice($seen, -self::SEEN_MAX, null, true);
                }
                $this->sse(($id !== null ? "id: {$id}\n" : '').'data: '.$json);
            };

            // Callback Pub/Sub: SIGNATURE ($message, $channel) -- bug utama (D1).
            $onMessage = function (string $message, string $chan) use ($emit) {
                $sig = json_decode($message, true);
                $emit($message, is_array($sig) && isset($sig['id']) ? (string) $sig['id'] : null);
            };

            $catchUp = function () use (&$cursor, $userId, $emit) { /* D2: query + emit, try/catch Throwable -> Log::warning, jangan matikan stream */ };

            try {
                [$connection, $client] = $this->connect();
            } catch (\Throwable $e) {
                Log::warning('SSE: koneksi Redis gagal, stream diakhiri', ['error' => $e->getMessage()]);
                return;                                              // D7
            }

            try {
                while ($this->shouldContinue()) {
                    $catchUp();                                      // SEBELUM subscribe (urutan penting)
                    try {
                        $connection->subscribe([$channel], $onMessage);
                    } catch (\RedisException $e) {
                        if ($e->getPrevious() !== null) {
                            Log::warning('SSE callback gagal', ['error' => $e->getPrevious()->getMessage()]);
                        }
                        try { [$connection, $client] = $this->connect(); }   // purge + reconnect
                        catch (\Throwable $e2) { Log::warning(...); break; }
                    } catch (\Throwable $e) {
                        Log::debug('SSE subscribe berhenti', ['error' => $e->getMessage()]);
                        break;
                    }
                    if (time() - $last >= $hb) { $this->sse(': ping'); $last = time(); }
                }
            } finally {
                try { $client->close(); } catch (\Throwable $e) {}
            }
        });
        // header sama seperti sekarang
    }

    /** @return array{0: object, 1: \Redis} */
    protected function connect(): array { /* purge('notifications'), connection, client()->setOption(\Redis::OPT_READ_TIMEOUT, self::TICK_SECONDS) */ }
    protected function prepareOutput(): void { @set_time_limit(0); while (ob_get_level() > 0) { @ob_end_flush(); } }
    protected function shouldContinue(): bool { return ! connection_aborted(); }
    protected function sse(string $line): void { /* echo + ob_flush + flush (sama seperti sekarang) */ }
    private function resolveCursor(?string $lastEventId, int $userId): string
    // Str::isUuid($lastEventId) -> DatabaseNotification where id, notifiable_type=User::class, notifiable_id=$userId -> created_at;
    // selain itu now(). Kembalikan string format('Y-m-d H:i:s.u').
}
```

Aturan catch-up (isi `$catchUp`):
- `DatabaseNotification::query()->where('notifiable_type', User::class)->where('notifiable_id', $userId)->where('created_at', '>', $cursor)->orderBy('created_at')->limit(self::CATCHUP_LIMIT)->get()`.
- Untuk tiap baris: `$emit(json_encode(NotificationService::signalFor($n), JSON_UNESCAPED_UNICODE), $n->id)`; `$cursor = $n->created_at->format('Y-m-d H:i:s.u')`.
- Bungkus `try/catch (\Throwable)` → `Log::warning('SSE catch-up gagal', ...)`; stream lanjut.
- Perhatikan: `$cursor` dipakai sebagai string; closure memakai `use (&$cursor)`.
- Catatan beban: satu query terindeks (index morph `notifiable_type, notifiable_id`) tiap 5 dtk per tab. Koneksi DB sudah terbuka selama stream (session `database` + auth) sehingga tidak menambah koneksi MySQL; namun `pm.max_children=128` berarti hingga 128 koneksi MySQL dari app (default `max_connections` MySQL 151) → dokumentasikan di README (1.9).

Verifikasi: `php artisan test --filter=NotificationStream` (lihat 3).

### 1.2 `app/Services/NotificationService.php`

Tambah `public static function signalFor(DatabaseNotification $n): array` mengembalikan `['id','type'(data.type ?? $n->type),'message','url','task_id'(data.task_id ?? null),'created_at'(toIso8601String)]`. `notify()` memakai fungsi ini untuk `publish()` (bentuk payload identik dengan sekarang). Impor `App\Models\DatabaseNotification`.
Verifikasi: `php artisan test --filter=NotificationTest` tetap hijau.

### 1.3 `config/database.php` (blok `redis.notifications`) dan `config/app.php`

- `database.php`: ganti baris `'read_timeout' => env('SSE_HEARTBEAT_SECONDS', 20)` + komentarnya dengan `'timeout' => 2` (connect) dan `'read_timeout' => 3` (batasi `publish()` di request), komentar Indonesia menjelaskan bahwa stream SSE menimpa read timeout sendiri dan heartbeat ada di `config('app.sse_heartbeat')`.
- `config/app.php`: tambah di akhir array: `'sse_heartbeat' => (int) env('SSE_HEARTBEAT_SECONDS', 20),` dengan docblock gaya file.
- Pastikan tidak ada pembaca lain `database.redis.notifications.read_timeout` (grep sebelum ubah; sejauh ini hanya controller).

### 1.4 `resources/js/composables/useNotifications.js`

Tambah state modul: `let safetyTimer = null; let connectGuard = null; let reconnectTimer = null; let signalSeq = 0;` dan konstanta `SAFETY_POLL_MS = 5*60*1000; CONNECT_GUARD_MS = 10000; RECONNECT_MS = 15000`.
- `openSse()`: setelah `new EventSource`, pasang `connectGuard = setTimeout(() => { if (!es || es.readyState !== EventSource.OPEN) startPolling(); }, CONNECT_GUARD_MS)`.
- `es.onopen`: `clearTimeout(connectGuard); stopPolling(); pollOnce();` (catch-up).
- `es.onerror`: `startPolling();` lalu bila `es.readyState === EventSource.CLOSED` (browser tak akan reconnect sendiri): `es.close(); es = null;` dan jadwalkan `reconnectTimer = setTimeout(() => { reconnectTimer = null; openSse(); }, RECONNECT_MS)` hanya jika `!reconnectTimer`.
- `handleSignal(sig)`: bila id sudah ada di `items` → `return` (tidak ada reload/efek ganda); `signalSeq++` bila baru; sisanya sama.
- `pollOnce()`: tangkap `const seq = signalSeq` sebelum fetch; setelah respons, bila `seq !== signalSeq` jangan menimpa `items` (SSE lebih baru); selain itu perilaku sama (sudah menggantikan seluruh list → tidak ada duplikasi).
- `init()`: setelah `openSse()`: `safetyTimer = setInterval(pollOnce, SAFETY_POLL_MS)` (guard `if (!safetyTimer)`).
- `onVisibilityChange()`: pada tab terlihat, selain logika yang ada panggil `pollOnce()` (catch-up setelah tab tersembunyi).
- `teardown()`: `clearInterval(safetyTimer)`, `clearTimeout(connectGuard)`, `clearTimeout(reconnectTimer)` dan set semuanya `null`, selain pembersihan yang sudah ada. Tidak ada timer tersisa → tidak ada kebocoran.
Verifikasi: `npm run build` sukses. Verifikasi perilaku: manual (lihat 4.3). Tidak ada framework tes JS; JANGAN menambah dependensi.

### 1.5 `.dockerignore` (baru)

```
.git
.gitignore
.gitattributes
.worktrees
.agents
.kiro
.codex
.vscode
.idea
.env
.env.*
!.env.example
node_modules
vendor
public/hot
public/build
public/storage
storage/logs/*
!storage/logs/.gitignore
storage/framework/cache/*
!storage/framework/cache/.gitignore
storage/framework/cache/data/*
storage/framework/sessions/*
!storage/framework/sessions/.gitignore
storage/framework/views/*
!storage/framework/views/.gitignore
storage/framework/testing
storage/app/private/*
storage/app/public/*
!storage/app/public/.gitignore
bootstrap/cache/*
!bootstrap/cache/.gitignore
tests
docs
.phpunit.result.cache
*.log
```
Sebelum menulis, `Get-ChildItem storage -Recurse -Force -Filter .gitignore` untuk memastikan berkas `.gitignore` yang dipertahankan memang ada; sesuaikan pola. Yang HARUS tetap ada di konteks: `app bootstrap config database routes resources public(kecuali hot/build) artisan composer.json composer.lock package.json package-lock.json vite.config.js docker/**`. `docker-compose.dev.yml` aman: `app`/`vite` memakai konteks `.` tetapi `Dockerfile.dev` hanya `COPY docker/php/php-fpm.conf`; kode dev dipasang lewat bind mount (`.:/var/www`) sehingga tidak terpengaruh filter konteks.
Verifikasi: `docker compose -f docker-compose.dev.yml build app` dan build prod (4.2).

### 1.6 `docker/php/Dockerfile` dan `docker/nginx/Dockerfile`

`docker/php/Dockerfile`:
1. Tambah stage pertama:
   ```
   FROM composer:2 AS ziggy
   WORKDIR /app
   COPY composer.json composer.lock ./
   RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --ignore-platform-reqs
   ```
2. Stage `node_builder`: setelah `RUN npm ci` tambah `COPY --from=ziggy /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy`; setelah `COPY public/ ./public/` tambah `RUN rm -f public/hot`; lalu `npm run build`.
3. Stage runtime: setelah `COPY . .` tambah `RUN rm -f public/hot` dan `RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache` sebelum `chown/chmod` yang ada.
`docker/nginx/Dockerfile`: tambah stage `ziggy` yang sama, dan di `node_builder` baris `COPY --from=ziggy ...` + `RUN rm -f public/hot`. Pastikan `public/hot` tidak ada di image akhir (cek di 4.2).
Catatan: tidak ada `.dockerignore` terpisah per Dockerfile; satu `.dockerignore` root cukup.

### 1.7 `docker/nginx/default.conf` (location `= /sse/notifications`)

`add_header` di dalam `location` membuang semua `add_header` level server (aturan inheritance nginx). Ganti satu baris `add_header X-Accel-Buffering no;` menjadi:
```
add_header X-Accel-Buffering no always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
```
Tambahkan komentar singkat alasan duplikasi. Verifikasi: `nginx -t` di container nginx dev dan `curl.exe -si http://localhost:8085/sse/notifications` menampilkan semua header tersebut (respons 302 ke login pun membawa header server-level; untuk memastikan header lokasi SSE, cek pada respons yang sudah login, 4.3).

### 1.8 `docker/php/php-fpm.conf`

`pm.start_servers = 20`, `pm.min_spare_servers = 10`, `pm.max_spare_servers = 40` (valid: min ≤ start ≤ max; `max_children=128` tetap). Tambahkan komentar: RAM ≈ jumlah worker × ~40 MB; satu tab = satu worker permanen.
Verifikasi: `docker compose -f docker-compose.dev.yml exec app php-fpm -tt 2>&1 | Select-String "pm\."` setelah rebuild image dev (berkas ini di-COPY ke image, bukan bind mount) dan `php-fpm -t` → "test is successful".

### 1.9 Dokumentasi: `README.md`, `.env.example`

`README.md`:
- Bagian "Notifikasi Realtime (SSE)" (sekitar baris 240): jelaskan catch-up DB (`Last-Event-ID`), tick 5 dtk, heartbeat, dan bahwa fallback polling/safety poll 5 menit ada.
- Bagian Docker (`### \`.env\` untuk produksi`, sekitar baris 264-300): tambah sub-bagian "Redis di host Linux": Redis harus listen di IP gateway Docker (mis. `bind 127.0.0.1 172.17.0.1`) atau `0.0.0.0` + firewall yang hanya mengizinkan subnet Docker; set `requirepass` dan `REDIS_PASSWORD` di `.env`; `REDIS_HOST=host.docker.internal`. Uji dari container: `docker compose exec app php -r "echo getenv('REDIS_HOST');"` dan `docker compose run --rm --entrypoint php app -r "..."`/`redis-cli -h host.docker.internal ping`. Nyatakan bahwa kegagalan publish hanya tercatat `Log::warning` (cari di `storage/logs/laravel.log`).
- Reverse proxy di depan port 8000: contoh `location = /sse/notifications { proxy_pass ...; proxy_buffering off; proxy_cache off; proxy_read_timeout 3600s; gzip off; proxy_http_version 1.1; proxy_set_header Connection ""; }`.
- Kapasitas: 1 tab = 1 worker php-fpm dan (potensial) 1 koneksi MySQL; sesuaikan `pm.max_children` dan `max_connections` MySQL host.
- Langkah menjalankan (baris ~288-293): ganti `exec app php artisan key:generate` menjadi generate di host / `docker compose run --rm app php artisan key:generate --show` (D14); pakai `docker compose exec -u www-data app php artisan ...` untuk perintah artisan; catatan `chown` satu kali untuk volume lama: `docker compose exec -u root app chown -R www-data:www-data /var/www/storage` (D13).
- Troubleshooting "Notifikasi tidak realtime": tambah langkah cek `curl -N` stream, `laravel.log` ("SSE callback gagal"/"Publish notifikasi Redis gagal"), dan `redis-cli -n 2 PUBSUB CHANNELS`.
`.env.example`: perbarui komentar blok SSE: "Read-timeout subscribe 5 dtk (konstanta `TICK_SECONDS`)", `SSE_HEARTBEAT_SECONDS` dibaca via `config('app.sse_heartbeat')`; tambahkan `# REDIS_PASSWORD=` petunjuk produksi dan pertahankan `REDIS_HOST=127.0.0.1` (default dev non-Docker) dengan komentar jelas bahwa di Docker produksi HARUS `host.docker.internal` (kalau tidak, publish gagal diam-diam). Jangan membaca `.env`.

### 1.10 `docker-compose.yml`

Service `queue`: tambah `user: www-data` (D13). Tidak ada perubahan lain (REDIS_HOST tetap dari `env_file`). Verifikasi: `docker compose -f docker-compose.yml config -q` (tanpa error).

## 2. Urutan dan fitur

- **FEAT-001 (kode: backend + frontend + tes)** = 1.1, 1.2, 1.3, 1.4 + tes (bagian 3). Mandiri dari Docker; diverifikasi dengan `php artisan test` dan `npm run build` lokal.
- **FEAT-002 (infra Docker + dokumentasi)** = 1.5-1.10. Tidak bergantung pada FEAT-001 untuk build, tetapi verifikasi e2e SSE butuh FEAT-001. Dikerjakan sesudah FEAT-001.
- Sesudah loop review: langkah `docker-verify` (rebuild nyata) dan `report`.

## 3. Rencana tes (gaya `tests/Feature/NotificationTest.php`)

Berkas baru `tests/Feature/NotificationStreamTest.php` (`RefreshDatabase`, helper `mockRole`/`actingAsKabid` disalin secukupnya, docblock Indonesia). Controller palsu diikat lewat `$this->app->bind(NotificationStreamController::class, fn () => new class extends NotificationStreamController { ... })` yang meng-override `connect()` (mengembalikan `[$fakeConnection, $fakeClient]`), `prepareOutput()` (no-op), `sse()` (hanya `echo $line."\n\n"`), dan `shouldContinue()` bila perlu. `$fakeConnection->subscribe(array $channels, \Closure $cb)` meniru wrapper Laravel: memanggil `$cb($message, $channel)` TEPAT dua argumen. Respons dibaca dengan `$this->get(route('notifications.stream'))->streamedContent()`.

Kasus (semua jalan tanpa ekstensi redis kecuali ditandai):
1. `test_callback_subscribe_menerima_dua_argumen_dan_meneruskan_pesan`: fake memanggil callback sekali dengan JSON bersama `id`; fake lalu melempar `\RuntimeException` agar loop berhenti (`catch \Throwable` → break). Asser output memuat `id: <uuid>` dan `data: {...}`. Tes ini GAGAL pada kode lama (signature 3 argumen → ArgumentCountError → tidak ada `data:`).
2. `test_pesan_duplikat_hanya_dikirim_sekali`: callback dipanggil dua kali dengan id sama → satu `data:`.
3. `test_catch_up_mengirim_notifikasi_setelah_last_event_id`: buat baris A lalu B, C untuk user; kirim header `Last-Event-ID: A`; `subscribe` langsung melempar `\RuntimeException` → output memuat B dan C (urut), tidak memuat A.
4. `test_last_event_id_milik_user_lain_diabaikan`: header berisi id notifikasi user lain → tidak ada data user lain di output (cursor jatuh ke `now()`).
5. `test_tick_redis_exception_membangun_ulang_koneksi_lalu_menerima_pesan` (`markTestSkipped` bila `! class_exists(\RedisException::class)`): subscribe pertama melempar `\RedisException`, kedua memanggil callback; `connect()` terpanggil 2 kali, pesan terkirim.
6. `test_koneksi_redis_gagal_mengakhiri_stream_tanpa_exception`: `connect()` melempar `\RuntimeException` → respons 200 dengan `retry: 5000`/`: connected`, tanpa `data:`.
7. (Opsional, integrasi) `test_integrasi_redis_nyata_menerima_publish`: `markTestSkipped` kecuali `extension_loaded('redis') && function_exists('pcntl_fork') && getenv('REDIS_INTEGRATION') === '1'` dan `Redis::connection('notifications')->ping()` berhasil. Memakai controller ASLI (tanpa override `connect()`), `shouldContinue()` berhenti setelah `data:` terlihat atau deadline 8 dtk; anak hasil `pcntl_fork` memanggil `Redis::purge('notifications')`, `sleep(1)`, `publish`, lalu keluar dengan `posix_kill(getmypid(), SIGKILL)`. Jalankan di container dev: `docker compose -f docker-compose.dev.yml exec -e REDIS_INTEGRATION=1 app php artisan test --filter=NotificationStreamTest`. Bila flaky, boleh tetap skip; tes 1-6 wajib.
8. Perbarui docblock `NotificationTest.php` (baris 19-20) agar menunjuk ke `NotificationStreamTest`. Tes `test_sse_endpoint_butuh_auth` dipertahankan.
9. Tes service: tambah satu tes kecil di `NotificationTest` (atau berkas baru) untuk `NotificationService::signalFor()` (kunci payload sama dengan yang di-publish).

Perintah: lokal `php artisan test --filter=Notification` lalu `php artisan test` (seluruh suite, harus tetap hijau; catat jumlah tes sebelum/sesudah). Di container: `docker compose -f docker-compose.dev.yml exec app php artisan test`.

## 4. Rencana verifikasi Docker nyata

### 4.1 Stack dev (port 8085) -- boleh rebuild
1. `docker compose -f docker-compose.dev.yml up -d --build app vite` (image dev dibangun ulang karena `php-fpm.conf` di-COPY) lalu `docker compose -f docker-compose.dev.yml restart nginx` setelah `exec nginx nginx -t`. JANGAN `down -v` (volume `sakai_vendor_dev`, `sakai_node_modules_dev`, `sakai_mysql_dev_data` berisi data).
2. `docker compose -f docker-compose.dev.yml ps` semua `Up`; `exec app php-fpm -tt 2>&1 | Select-String "pm\."` → `start_servers=20`, `min_spare=10`, `max_spare=40`.
3. Tes dalam container: `exec app php artisan test` (+ `REDIS_INTEGRATION=1` untuk tes 7).
4. E2E SSE lewat nginx 8085 (butuh sesi login): buat user/sesi uji tanpa kredensial dengan `exec app php artisan tinker` (user yang rolenya ter-resolve; jika `RoleResolver` butuh DB SIKEP host dan gagal, catat dan pakai fallback): tulis baris sesi lewat `app('session')->driver()` (`setId`, `start`, `put(Auth::guard('web')->getName(), $user->id)`, `save`) dan buat cookie `Crypt::encryptString(CookieValuePrefix::create(config('session.cookie'), Crypt::getKey()).$id)`. Lalu:
   - `curl.exe -N -H "Cookie: <session>=<cookie>" http://localhost:8085/sse/notifications` di satu proses (background) → harus muncul `retry: 5000`, `: connected`, header `Content-Type: text/event-stream`, `X-Accel-Buffering: no` dan header keamanan (1.7).
   - Di proses lain: `exec app php artisan tinker --execute="app(App\Services\NotificationService::class)->notify(<sikep_user_id>, 'task.approved', 'Uji realtime', '/dashboard', ['task_id'=>1]);"` → baris `id: ...` + `data: {...}` muncul <2 dtk. Ulangi beberapa kali dengan jeda acak 0-6 dtk termasuk di sekitar batas tick 5 dtk (uji celah); setiap notifikasi harus tiba tepat satu kali.
   - Uji catch-up: matikan stream, buat notifikasi, sambung lagi dengan `-H "Last-Event-ID: <id sebelumnya>"` → notifikasi tertinggal terkirim di awal.
   - Uji ketahanan: `docker stop sakai_redis_dev` → stream berakhir tanpa 500, `laravel.log` memuat warning; `docker start sakai_redis_dev` → klien baru pulih. Pastikan `notify()` saat Redis mati kembali ≤5 dtk dan tidak error.
   - Fallback bila login terblokir middleware: jalankan tes integrasi 7 di container (membuktikan controller asli + phpredis + Redis nyata) dan skrip diagnostik seperti `findings.md` §4 versi baru; catat sebagai keterbatasan.
5. Browser (manual, oleh user bila tidak ada otomasi): dua sesi, DevTools Network `eventsource`, ajukan tugas → titik merah <2 dtk tanpa reload; setelah `docker restart sakai_nginx_dev` stream tersambung kembali tanpa reload. Bersihkan semua sesi/baris uji yang dibuat (hapus baris `sessions` dan `notifications` uji).

### 4.2 Build produksi (tanpa mengganggu port 8000 / stack lain)
1. `docker compose -f docker-compose.yml config -q`.
2. `docker compose -p rtprodtest -f docker-compose.yml build app nginx` (nama proyek terpisah; `container_name` tetap `sakai_app/sakai_nginx/sakai_queue` tidak bentrok dengan `*_dev`). Harus sukses: stage Node menemukan Ziggy, `npm run build` sukses.
3. Isi image: `docker run --rm --entrypoint sh <image app> -c "test ! -e public/hot && test ! -e .env && test -d storage/framework/views && ls public/build/manifest.json && php -v"`; cek `vendor/` tanpa paket dev (`test ! -d vendor/phpunit`) dan `bootstrap/cache` tidak memuat `packages.php` dari host; image nginx: `test ! -e /var/www/public/hot` dan `ls /var/www/public/build/manifest.json`; bandingkan hash `manifest.json` kedua image (konsisten).
4. Smoke: `$env:APP_PORT=8091; docker compose -p rtprodtest -f docker-compose.yml up -d app nginx` (queue dilewati; `.env` lokal menunjuk DB host sehingga request penuh mungkin gagal, itu bukan kegagalan build) → `curl.exe -si http://localhost:8091/build/manifest.json` 200, `/sse/notifications` 302 dengan header; `exec nginx nginx -t` sukses; `exec -u www-data app php -r "echo 'ok';"`. Setelah itu `docker compose -p rtprodtest -f docker-compose.yml down` (TANPA `-v` bila volume tidak dibuat; volume proyek uji `rtprodtest_sakai_storage` boleh dihapus karena milik proyek uji) dan `docker image rm` image uji bila perlu. Catat bahwa Redis host/firewall produksi dan reverse proxy host TIDAK dapat diverifikasi di sini.
5. Verifikasi `user: www-data` pada queue: `docker compose -p rtprodtest -f docker-compose.yml config` memuat `user: www-data`; jangan menjalankan queue worker sungguhan.

### 4.3 Verifikasi frontend (manual/e2e)
`npm run build` (host) sukses. Di browser: (a) matikan stream (blokir `/sse/notifications` di DevTools) → setelah 10 dtk polling menyala; (b) buka lagi → `pollOnce` pada `onopen`, polling berhenti; (c) tab disembunyikan >60 dtk lalu dibuka → daftar tersegarkan; (d) tidak ada notifikasi ganda di panel; (e) setelah navigasi SPA berulang hanya ada satu `EventSource` (tab Network). Bila tidak ada otomasi browser, laporkan sebagai "tidak diverifikasi otomatis".

## 5. Risiko dan hal yang tidak dapat diverifikasi
- Redis host produksi (bind, requirepass, firewall), reverse proxy/CDN di depan port 8000, beban banyak tab, dan perilaku browser nyata tidak bisa diverifikasi di mesin ini.
- `composer install --ignore-platform-reqs` di stage `ziggy` mengunduh seluruh dependensi (hanya Ziggy dipakai); diterima demi kesederhanaan, ter-cache oleh layer.
- Perubahan tick 1→5 dtk menggeser "pesan di celah" menjadi tertunda ≤5 dtk (bukan hilang). Bila perlu lebih cepat, ubah `TICK_SECONDS` (satu konstanta).
- Perubahan perilaku yang terlihat pengguna: notifikasi yang sebelumnya hilang kini muncul (maks 5 dtk tertunda); tidak ada perubahan UI.
