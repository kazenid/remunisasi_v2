# Laporan Investigasi: Notifikasi Realtime (SSE) tidak jalan di Docker

Mode: READ-ONLY. Tidak ada file proyek yang diubah. Satu skrip diagnostik sementara dibuat di `/tmp` container `sakai_app_dev` lalu dihapus (isi `/tmp` sudah dicek bersih).

## 1. Jawaban singkat

**Akar masalah utama BUKAN konfigurasi Docker/nginx, melainkan bug di kode controller SSE.** Bug ini terjadi di lingkungan mana pun yang menjalankan stream SSE (php-fpm), termasuk Docker.

`NotificationStreamController::stream()` mendaftarkan callback `function ($redis, $chan, $message)` (3 argumen) ke `Illuminate\Redis\Connections\PhpRedisConnection::subscribe()`. Wrapper Laravel itu memanggil callback hanya dengan 2 argumen, `$callback($message, $channel)` (`vendor/laravel/framework/src/Illuminate/Redis/Connections/PhpRedisConnection.php:461-463`).

Akibatnya setiap pesan Redis yang masuk memicu `ArgumentCountError`. Error itu ditelan phpredis dan muncul sebagai `previous` dari `RedisException` berikutnya. Controller menangkap `\RedisException` (baris ~53) dan hanya membangun ulang koneksi, jadi pesan hilang tanpa log.

Dampak yang cocok dengan keluhan:
- Stream SSE tetap terbuka dan heartbeat jalan, sehingga `EventSource.onopen` terpanggil.
- `onopen` memanggil `stopPolling()` (`useNotifications.js:25`), jadi polling fallback mati.
- Notifikasi tidak pernah sampai ke browser. Baru terlihat setelah reload halaman, karena shared prop `notifications` dibaca dari DB.

Ini **terbukti secara empiris** di container dev yang sedang berjalan (bagian 4).

Akar masalah sekunder (Docker dan frontend) ada di bagian 5. Yang paling penting:
- Jika `docker compose up --build` (produksi) dijalankan dari working dir ini, ada risiko build karena `.dockerignore` tidak ada dan stage Node tidak punya `vendor/` (import Ziggy).
- Fallback polling tidak aktif selama SSE "tersambung".

## 2. Arsitektur realtime (hasil pembacaan kode)

| Lapisan | Implementasi | Lokasi |
|---|---|---|
| Sumber kebenaran | Tabel `notifications` (DB) | `app/Services/NotificationService.php::notify()` |
| Sinyal realtime | Redis Pub/Sub, channel `notif.user.{sikep_user_id}`, koneksi khusus `notifications` (prefix kosong, DB 2) | `NotificationService::publish()` (sinkron, di request, best-effort, error ditelan + `Log::warning`); `config/database.php` blok `redis.notifications` |
| Pemicu | `TaskService`, `AssignmentService`, `ReviewService` memanggil `$notifier->notify(...)` | `app/Services/*.php` |
| Endpoint SSE | `GET /sse/notifications` (nama route `notifications.stream`), middleware `auth, active-role, two-factor` | `routes/web.php:55`, `app/Http/Controllers/NotificationStreamController.php` |
| Loop stream | `set_time_limit(0)`, `ob_end_flush` semua level, `echo` + `ob_flush` + `flush` per baris, subscribe dengan `OPT_READ_TIMEOUT=1` dtk, tiap `RedisException` melakukan `Redis::purge` lalu reconnect, heartbeat `: ping` tiap `SSE_HEARTBEAT_SECONDS` (default 20) | Controller baris ~23-75 |
| Header | `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`, `Connection: keep-alive` | Controller baris 76-79 |
| Frontend | Singleton `EventSource` di `resources/js/composables/useNotifications.js`. `onerror` memanggil `startPolling()` (tanpa `close()`), `onopen` memanggil `stopPolling()`. Polling 60 dtk ke `notifications.index`. | `useNotifications.js:22-36, 82-107, 116-126` |
| UI | `AppLayout.vue` memanggil `init()` saat mounted. Lonceng dan popover di `AppTopbar.vue`. | |
| Auth/session | Session `database`, tanpa session lock. `StartSession` menyimpan session di `handle()` sebelum response dikirim, jadi tidak ada lock yang ditahan selama stream. Tidak ada `session_write_close` dan tidak diperlukan. | `.env` (`SESSION_DRIVER=database`) |
| Queue/scheduler/Reverb | **Tidak dipakai** untuk realtime. Publish sinkron. `routes/console.php` tidak punya schedule. | |
| Ziggy | `route('notifications.stream')` di JS memakai URL root dari `@routes` (`resources/views/app.blade.php`), yaitu root request. Tidak ada `forceRootUrl`/`forceScheme` di `app/bootstrap/config/routes`. | |

## 3. Temuan konfigurasi Docker (dengan file:baris)

Stack yang terlihat berjalan di mesin ini adalah **`docker-compose.dev.yml`** (project `remunisasi_v2`: `sakai_app_dev`, `sakai_nginx_dev` di port 8085, `sakai_redis_dev`, `sakai_mysql_dev`, `sakai_vite_dev`). Jika yang dimaksud user adalah stack ini, konfigurasi Docker-nya sehat. Hasil pengecekan di dalam container:

| Item | Hasil | Verifikasi |
|---|---|---|
| phpredis | terpasang, v6.3.0 | `php --ri redis` di container |
| `output_buffering` | 0 | `php -i` |
| `zlib.output_compression` | Off | `php -i` |
| `max_execution_time` | 0 (CLI) dan controller memanggil `set_time_limit(0)` | |
| `clear_env` | `no`, jadi env compose terbaca PHP-FPM | `php-fpm -tt` |
| `pm.max_children` | 128 | `docker/php/php-fpm.conf:13`, `php-fpm -tt` |
| `request_terminate_timeout` | 3700s | `docker/php/php-fpm.conf:18` |
| `REDIS_HOST` | `redis` (override compose; `.env` lokal berisi 127.0.0.1 tetapi process-env menang) | `env` di container; `docker-compose.dev.yml:28,70` |
| Route SSE lewat nginx | 302 ke `/login` (belum login), header `X-Accel-Buffering: no`, `Transfer-Encoding: chunked` | `curl -i http://localhost:8085/sse/notifications` |
| Redis pub/sub antar-container | **bekerja** (publish dan subscribe sampai) | skrip diagnostik |

Konfigurasi nginx SSE sudah benar (`docker/nginx/default.conf:22-34`):
- `location = /sse/notifications` berada sebelum `location ~ \.php$`.
- `fastcgi_buffering off` (baris 29), `fastcgi_read_timeout 3600s` (baris 30), `gzip off` (baris 31).
- `gzip_types` (baris 67) tidak memuat `text/event-stream`, jadi tidak ada kompresi stream.
- Kekurangan kecil: `add_header X-Accel-Buffering no` di dalam location (baris 32) membuang header keamanan level server pada endpoint ini. Dampaknya kosmetik.

Temuan untuk **stack produksi** (`docker-compose.yml` + `docker/php/Dockerfile` + `docker/nginx/Dockerfile`). Tidak dijalankan di sini; hasil pembacaan statis:

1. **Tidak ada `.dockerignore`** (`Test-Path .dockerignore` bernilai False).
   - `docker/php/Dockerfile:78` (`COPY . .`) menyalin `.env` (APP_ENV=local, APP_DEBUG=true), `vendor/` host (termasuk paket dev, menimpa hasil `composer install --no-dev` di baris 76), `node_modules`, `.git`, `.worktrees`, dan `bootstrap/cache/*.php` (`packages.php`/`services.php` bisa memuat provider dev).
   - **`public/hot` saat ini ada** (isi `http://localhost:5173`). Terbawa ke image app lewat `COPY . .` dan ke image nginx lewat `COPY --from=node_builder /app/public` (`docker/nginx/Dockerfile:15`). Jika `public/hot` ada di image produksi, `@vite` memuat aset dari dev server `localhost:5173` dan halaman produksi rusak.
2. **Stage Node tidak punya `vendor/`.** `resources/js/app.js` mengimpor `'../../vendor/tightenco/ziggy'`, sedangkan `docker/php/Dockerfile:15-17` dan `docker/nginx/Dockerfile:9-11` hanya menyalin `vite.config.js`, `resources/`, `public/`. `npm run build` (baris 20 / baris 12) kemungkinan besar gagal ("Could not resolve"). **Tidak dieksekusi** (konteks build tanpa `.dockerignore` sangat besar). Impor ini sudah ada sejak commit awal `1489d07`.
3. **Redis di host Linux** (`docker-compose.yml` header, `README.md:276`). README hanya mengingatkan grant MySQL, tidak untuk Redis. Redis di host harus listen di IP yang dapat dijangkau gateway Docker (bukan hanya 127.0.0.1), `protected-mode`/`requirepass` diset dan firewall mengizinkan. Jika tidak, `publish()` gagal diam-diam (`Log::warning`) dan stream SSE memantul. Tidak ada timeout koneksi Redis di `config/database.php:~208-222`, sehingga connect yang di-DROP firewall bisa menahan request submit/review sampai timeout TCP OS.
4. **Dua build Vite terpisah** (image app dan image nginx). Hash aset sama selama sumber sama, tetapi menggandakan waktu build dan risiko drift.
5. **Container `queue` berjalan sebagai root** dan berbagi volume `sakai_storage` dengan app (`docker-compose.yml`, service `queue`). `laravel.log` yang dibuat root dapat memblokir tulis php-fpm (www-data). Bukan penyebab realtime, tetapi risiko operasional.
6. **php-fpm `pm = dynamic`** dengan `start_servers=5` (`docker/php/php-fpm.conf:11-14`). Setelah restart, semua klien SSE reconnect bersamaan. Dynamic PM menambah worker bertahap, sehingga request halaman biasa bisa antre sebentar. Satu tab = satu worker permanen.
7. **Reverse proxy di depan port 8000**: `docker-compose.yml` hanya memetakan `${APP_PORT:-8000}:80`. Jika di host ada nginx/Apache/Cloudflare/Traefik di depan, proxy itu harus `proxy_buffering off` untuk `/sse/notifications`. Konfigurasi proxy host tidak ada di repo, jadi **tidak terverifikasi**.

## 4. Bukti empiris bug callback (reproduksi di container dev)

Skrip sementara dijalankan di `sakai_app_dev` (PHP 8.4, phpredis 6.3.0, Redis 7). Skrip mem-bootstrap Laravel dan memakai pola loop persis seperti controller (`Redis::purge`, `OPT_READ_TIMEOUT=1`, `subscribe`, `catch \RedisException` lalu reconnect). Proses anak mem-publish ke channel uji `diag.test` pada detik ke-2.

Mode A: callback 3 argumen seperti controller sekarang. Pesan tidak pernah diterima:
```
[RedisException @1] read error on connection to redis:6379
[RedisException @3] read error on connection to redis:6379  PREVIOUS=ArgumentCountError: Too few arguments to function {closure}(), 2 passed in .../PhpRedisConnection.php on line 461 and exactly 3 expected
[RedisException @4] ...
```

Mode B: callback 2 argumen `($message, $channel)`. Pesan diterima:
```
[RedisException @1] read error on connection to redis:6379
[MSG-B] chan=diag.test msg={"x":1}
```

Publisher memang mencapai subscriber (`publish` mengembalikan `receivers=1`). Pengiriman sampai ke Redis baik; kerusakan ada di sisi callback.

Pemeriksaan tambahan:
- `tests/Feature/NotificationTest.php:286` hanya menguji bahwa stream butuh auth (redirect login).
- `verification.md:61-63` menyatakan jalur SSE/Redis live belum pernah diuji dan menunggu smoke test manual. Jadi bug ini lolos karena tidak pernah ada tes end-to-end.

## 5. Daftar akar masalah, urut berdasarkan kemungkinan

1. **(Terbukti, penyebab utama) Callback `subscribe` salah signature** (`NotificationStreamController.php:~50-53`). Pesan Redis selalu hilang. Gejala: stream hidup, `onopen` mematikan polling, tidak ada notifikasi sampai reload.
2. **(Desain, memperparah #1) Fallback polling mati selama SSE "open"** (`useNotifications.js:25`). `init()` tidak menyalakan polling sebelum `onerror`. Jika stream tersangkut CONNECTING karena proxy buffering di depan nginx, tidak ada `onerror`, tidak ada polling, dan user harus reload.
3. **(Desain) Celah pesan hilang di antara tick.** Setiap ~1 detik koneksi di-`purge` dan dibuat ulang. Pub/Sub Redis tidak menyimpan pesan, jadi pesan yang di-publish di jeda reconnect hilang, dan tidak ada catch-up karena polling mati. Ini juga berarti satu koneksi TCP Redis baru per detik per tab terbuka.
4. **(Produksi) Redis host tidak terjangkau dari container** (bind 127.0.0.1, protected-mode, firewall, `REDIS_HOST` masih 127.0.0.1 dari `.env`). `publish()` menelan error. Tidak terverifikasi.
5. **(Produksi) Build produksi bermasalah** (tidak ada `.dockerignore`, `public/hot` terbawa, `vendor/` tidak ada di stage Node). Bukan penyebab "tidak realtime", tetapi akan menghalangi atau merusak build produksi.
6. **(Produksi) Proxy host di depan nginx container** buffering SSE. Tidak terverifikasi.
7. **(Kapasitas)** FPM dynamic start 5 worker; 1 tab = 1 worker.

Yang dikesampingkan dengan bukti:
- Buffering nginx/gzip (konfigurasi benar, header `X-Accel-Buffering: no` terlihat).
- `output_buffering`, `zlib.output_compression`, `max_execution_time` (semua aman).
- `request_terminate_timeout` dan `fastcgi_read_timeout` (3700s dan 3600s, konsisten).
- Session lock (driver `database`, session disimpan sebelum stream dimulai).
- Queue worker/scheduler/Reverb tidak diperlukan.
- Cache driver per-container (tidak dipakai).
- `clear_env` (sudah `no`).
- phpredis tidak terpasang (terpasang, v6.3.0).
- Mismatch scheme/host Ziggy: tidak ada `forceRootUrl`, URL mengikuti request. Selama aset Vite juga dimuat (yang ikut root request), scheme konsisten.

## 6. Perubahan yang direkomendasikan (belum diterapkan)

### 6.1 Wajib: `app/Http/Controllers/NotificationStreamController.php`

```diff
-                        $connection->subscribe([$channel], function ($redis, $chan, $message) {
-                            $this->sse('data: '.$message);
-                        });
+                        // Wrapper Laravel memanggil callback dengan ($message, $channel).
+                        $connection->subscribe([$channel], function (string $message, string $chan) {
+                            $this->sse('data: '.$message);
+                        });
                     } catch (\RedisException $e) {
+                        // Error di dalam callback muncul sebagai previous; jangan ditelan diam-diam.
+                        if ($e->getPrevious() !== null) {
+                            Log::warning('SSE callback gagal', ['error' => $e->getPrevious()->getMessage()]);
+                        }
                         // Tick idle 1 dtk ATAU transien: ...
                         [$connection, $client] = $connect();
```

Alternatif yang lebih tegas: panggil `$client->subscribe([$channel], function ($redis, $chan, $message) {...})` langsung pada `\Redis` mentah, karena signature phpredis memang 3 argumen. Pilih salah satu. Rekomendasi: perbaiki signature saja, perubahan paling kecil.

### 6.2 Dianjurkan: `resources/js/composables/useNotifications.js`

Tutup celah #2 dan #3 dengan mengambil ulang saat tersambung dan menjaga safety poll lambat:
```diff
-    es.onopen = () => stopPolling(); // SSE tersambung → matikan fallback
+    es.onopen = () => {
+        stopPolling();
+        pollOnce();                  // catch-up: ambil apa pun yang terlewat saat reconnect
+    };
```
Untuk safety poll lambat selama SSE terbuka, tambahkan di `init()` setelah `openSse()` sebuah `setInterval(pollOnce, 5 * 60 * 1000)` yang dibersihkan di `teardown()`. Atau, lebih ringan, panggil `startPolling()` pada `init()` bila `es.readyState !== EventSource.OPEN` setelah ~10 dtk (timer CONNECTING-stuck).

### 6.3 Dianjurkan: `config/database.php` (blok `redis.notifications`)

Cegah request submit/review menggantung bila Redis tidak terjangkau:
```diff
         'notifications' => [
             ...
+            'timeout' => 2,          // connect timeout (dtk); publish tidak boleh menahan request
```
Catatan: key `read_timeout` pada blok ini dipakai sebagai interval heartbeat (`SSE_HEARTBEAT_SECONDS`), padahal Laravel juga membacanya sebagai read timeout klien phpredis. Pada loop SSE nilainya ditimpa `setOption(OPT_READ_TIMEOUT, 1)`, jadi tidak berbahaya, tetapi namanya menyesatkan. Opsional: pindahkan sumber heartbeat ke `env('SSE_HEARTBEAT_SECONDS')` / `config('app.sse_heartbeat')`.

### 6.4 Wajib untuk build produksi: `.dockerignore` baru

```
.git
.worktrees
.agents
.kiro
.env
.env.*
!.env.example
node_modules
vendor
public/hot
public/build
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
bootstrap/cache/*.php
tests
docs
```

### 6.5 `docker/php/Dockerfile` dan `docker/nginx/Dockerfile`: sediakan Ziggy di stage Node

```dockerfile
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --ignore-platform-reqs

FROM node:22-alpine AS node_builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
COPY vite.config.js ./
COPY resources/ ./resources/
COPY public/ ./public/
RUN npm run build
```
Hal yang sama berlaku untuk `docker/nginx/Dockerfile` (atau, lebih bersih, nginx menyalin `public/build` dari image app: `COPY --from=sakai-app /var/www/public /var/www/public`).

### 6.6 `docker/nginx/default.conf` (kecil)

Pertahankan header keamanan pada location SSE:
```diff
     location = /sse/notifications {
         ...
         gzip off;
-        add_header X-Accel-Buffering no;
+        add_header X-Accel-Buffering no always;
+        add_header X-Content-Type-Options "nosniff" always;
+        add_header X-Frame-Options "SAMEORIGIN" always;
```
Jika ada proxy host di depan container, tambahkan pada proxy itu: `proxy_buffering off; proxy_cache off; proxy_read_timeout 3600s; gzip off;` untuk path `/sse/notifications`.

### 6.7 `docker/php/php-fpm.conf` (opsional, kapasitas)

```diff
-pm.start_servers = 5
-pm.min_spare_servers = 5
-pm.max_spare_servers = 35
+pm.start_servers = 20
+pm.min_spare_servers = 10
+pm.max_spare_servers = 40
```
Atau pisahkan pool khusus SSE (lebih rapi, sesuai catatan desain §10.2). Sesuaikan dengan RAM; tiap worker SSE menahan satu proses penuh.

### 6.8 Dokumentasi / `.env` produksi

- `README.md` bagian Docker: tambahkan syarat Redis host (bind ke IP gateway Docker atau `0.0.0.0` dengan firewall, `requirepass`, isi `REDIS_PASSWORD`).
- Pastikan `.env` produksi berisi `REDIS_HOST=host.docker.internal`. `.env.example` baris 61 masih `127.0.0.1`, dan nilai ini terbawa lewat `env_file` ke container.
- Tambahkan smoke test manual (bagian 7) ke langkah deploy.

### 6.9 Tes yang disarankan

Tambahkan uji integrasi opsional (di-skip bila Redis tidak ada) yang mem-publish ke `notif.user.{id}` dan memastikan `data:` tiba di stream. Itu satu-satunya cara bug #1 tertangkap otomatis. Skrip diagnostik di bagian 4 dapat dijadikan dasar.

## 7. Smoke test manual setelah perbaikan

1. Login sebagai dua user (Katimker dan Kabid) di dua browser.
2. Buka DevTools lalu Network, filter `sse/notifications`. Harus ada satu request pending bertipe `eventsource` dengan baris `: connected` dan `: ping` tiap 20 dtk.
3. Dari Katimker, ajukan tugas. Titik merah di lonceng Kabid harus muncul dalam 1-2 dtk tanpa reload.
4. Matikan Redis (`docker stop sakai_redis_dev`). Request submit tetap sukses, lonceng terisi lewat polling, tidak ada error 500.
5. Hidupkan lagi Redis dan pastikan realtime kembali tanpa reload.
6. Ulangi lewat reverse proxy produksi (bila ada) untuk memastikan stream tidak ter-buffer.

## 8. Apa yang bisa dan tidak bisa diverifikasi

Terverifikasi:
- Pembacaan semua file konfigurasi (compose prod dan dev, kedua Dockerfile php, Dockerfile nginx, `default.conf`, `php-fpm.conf`, `.env.example`, `.env` hanya kunci non-rahasia, `config/database.php`, `bootstrap/app.php`, routes, controller, service, composable).
- Reproduksi bug callback di container dev dengan Redis nyata (mode A vs B).
- Konfigurasi runtime php/fpm di container dev (`php -i`, `php-fpm -tt`, `env`).
- Route SSE terjangkau lewat nginx dev (`localhost:8085`) dan header `X-Accel-Buffering` keluar.
- Tidak ada `.dockerignore`; `public/hot` ada di working dir.

Tidak terverifikasi:
- Aliran SSE end-to-end dengan sesi login nyata (tidak ada kredensial). Alur dibuktikan lewat skrip yang meniru loop controller, bukan lewat HTTP.
- Build produksi (`docker compose up --build`): tidak dijalankan karena konteks besar tanpa `.dockerignore`. Dugaan kegagalan stage Node (Ziggy) berasal dari pembacaan statis.
- Perilaku proxy/CDN host di depan port 8000 produksi dan konfigurasi Redis host produksi (`bind`, `requirepass`, firewall).
- Nilai `.env` produksi sebenarnya (`.env.production` tidak ada di mesin ini).
- Beban: perilaku dengan banyak tab/pengguna bersamaan (batas worker FPM).
- `php` lokal (Windows 8.3) tidak memiliki ekstensi `redis` dan `pcntl`, sehingga SSE tidak dapat diuji lokal di luar Docker.
