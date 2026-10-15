# Notifikasi realtime SSE: perbaikan signature subscribe, catch-up DB, dan hardening Docker

Akar masalah "harus reload" memang ada di `NotificationStreamController`: callback `subscribe` lama berbentuk `($redis, $chan, $message)`, padahal `PhpRedisConnection::subscribe()` memanggilnya dengan dua argumen `($message, $channel)` (vendor baris 458-463). Perubahan ini memperbaiki signature, menutup celah pesan hilang saat koneksi subscribe dibangun ulang lewat catch-up berbasis tabel `notifications` (`Last-Event-ID`, dedup, cursor mikrodetik), memperkuat `useNotifications.js` (pollOnce saat onopen, safety poll, guard CONNECTING, reopen saat CLOSED), dan merapikan Docker (`.dockerignore`, stage `ziggy`, header nginx, php-fpm, `user: www-data` pada queue, README/.env.example). Saya menjalankan ulang `php artisan test --filter=Notification`: 21 lulus, 2 skip (tes RedisException tick dan integrasi, butuh ext redis).

Watch for: (1) tidak ada backoff bila `subscribe()` gagal seketika berulang, tiap putaran memicu query DB + koneksi Redis baru (possible, LOW); (2) jalur rebuild `RedisException` dan integrasi Redis nyata belum terbukti, skip di PHP lokal (confirmed, LOW, ditutup oleh langkah docker-verify); (3) build image nyata dan e2e SSE belum dijalankan, container dev masih image lama (confirmed, pending docker-verify); (4) `package-lock.json` berubah `name` menjadi `www` akibat `npm install` di container (confirmed, LOW).

**Verdict**: APPROVED

## High-level view

Perbaikan inti sudah benar dan terbukti. Callback `function (string $message, string $chan)` cocok dengan wrapper Laravel, dan tes 1 menangkap regresinya: FEAT-001 mencatat mutasi ke 3 argumen membuat tes gagal (2 failed), lalu dikembalikan. Fake koneksi memanggil callback dengan tepat dua argumen, jadi tes memang mengikat kontrak vendor.

Catch-up ke DB mengubah pengiriman menjadi at-least-once. Pesan yang jatuh di celah antar-tick pulih paling lambat sekitar 5 detik. Cursor memakai string mikrodetik dan kolom `created_at` adalah `timestamp(6)`, jadi presisi cocok. Kesalahan cursor hanya bisa menghasilkan duplikat (diserap `$seen` di server dan dedup id di frontend), bukan kehilangan. `Last-Event-ID` divalidasi UUID dan kepemilikan user, query memakai binding, jadi tidak ada kebocoran lintas-user atau injeksi.

`useNotifications.js` menambah tiga timer (safety, guard, reconnect) dan semuanya dibersihkan di `teardown()`. `init()` idempoten, `openSse()` menolak koneksi ganda, `startPolling()` menjaga satu interval, dan `pollOnce` tidak menimpa data SSE yang lebih baru lewat `signalSeq`. Singleton modul tidak berubah, jadi tidak ada regresi navigasi SPA.

Config: heartbeat dipindah ke `config('app.sse_heartbeat')`, dan `redis.notifications` diberi `timeout=2`, `read_timeout=3` agar `publish()` di request submit/review terbatas. Stream menimpa read-timeout sendiri (5 dtk).

Docker: `.dockerignore` mengeluarkan `.env`, `vendor`, `public/hot`, `public/build`, tetapi mempertahankan `docker/` (dibutuhkan `COPY docker/php/php-fpm.conf` di `Dockerfile.dev`) dan kerangka `.gitignore` storage/bootstrap-cache (semua berkasnya ada, saya cek). Bind mount dev tidak terpengaruh. Stage `ziggy` menyediakan `vendor/tightenco/ziggy` untuk `app.js`. Hilangnya `.env` dari image tidak merusak php-fpm: `clear_env = no` ada di `docker.conf` bawaan image dan tidak ditimpa `www.conf` kustom (dicek di container dev). Header keamanan diulang di location SSE karena aturan inheritance `add_header`. pm 20/10/40 valid (min <= start <= max).

<details>
<summary>Issues (7)</summary>

1. **LOW, Tanpa backoff pada kegagalan subscribe cepat** (possible, `NotificationStreamController.php`, loop `while`) — bila `subscribe()` melempar `RedisException` seketika berulang (connect sukses, subscribe gagal), tiap putaran menjalankan catch-up (query DB) + `connect()` tanpa jeda, dan abort klien baru terdeteksi saat heartbeat (20 dtk). Tambahkan `usleep` kecil atau hitung putaran yang durasinya < 1 dtk lalu tunda/akhiri stream.
2. **LOW, Jalur RedisException dan integrasi belum terbukti** (confirmed, `NotificationStreamTest`) — kasus 5 dan 7 skip di PHP lokal tanpa ext redis; jalankan di container dev (`exec -e REDIS_INTEGRATION=1 app php artisan test --filter=NotificationStreamTest`) dan catat hasilnya di docker-verify.
3. **LOW, Catch-up pada putaran kedua dst. tidak diuji** (confirmed) — tes hanya mencakup catch-up putaran pertama; cursor yang maju lintas putaran dan dedup lintas tick belum punya tes. Tambahkan tes dengan dua panggilan `subscribe` fake (notifikasi dibuat di antara keduanya).
4. **LOW, Noise `package-lock.json`** (confirmed) — `"name": "remunisasi_v2"` menjadi `"www"` karena `npm install` dijalankan di `/var/www` dalam container vite. Kembalikan lewat `git checkout -- package-lock.json` agar tidak ikut commit.
5. **LOW, Verifikasi Docker nyata masih tertunda** (confirmed) — `verification.md` baru mencakup `config -q` dan `build --check`. Belum ada: build prod (stage ziggy, `npm run build`, `composer dump-autoload` tanpa `.env` dan `tests/`), isi image (tanpa `.env`/`public/hot`/`vendor/phpunit`), `php-fpm -tt` setelah rebuild dev (container dev sekarang masih 5/5/35), header SSE pada sesi login, e2e SSE. Tutup di langkah docker-verify.
6. **NIT, Dua fetch saat tab kembali terlihat** (confirmed, `useNotifications.js` `onVisibilityChange`) — `startPolling()` sudah memanggil `pollOnce()` lalu dipanggil lagi di baris berikutnya saat SSE belum OPEN. Tidak berbahaya; bisa diringkas.
7. **NIT, Kecil-kecil** — `User::class` pada query catch-up sebaiknya `(new User)->getMorphClass()` bila kelak ada morph map (likely, tidak berdampak sekarang); `X-XSS-Protection` sudah usang (nginx lokasi SSE hanya menyamai server-level); cursor berbasis `created_at` bisa melewatkan baris yang commit-nya tidak berurutan dengan timestamp dari catch-up, tetapi Pub/Sub dan `pollOnce` saat onopen tetap menutupnya (possible).

</details>

<details>
<summary>Details</summary>

### Signature callback dan tes yang mengikatnya

Controller lama memakai `function ($redis, $chan, $message)`. Wrapper Laravel membungkus callback phpredis dan memanggil `$callback($message, $channel)`, jadi `$message` selalu diisi `$channel`, dan argumen ketiga hilang. Hasilnya `ArgumentCountError` tertelan `catch (\Throwable)`, loop berhenti, dan tidak ada `data:` yang pernah terkirim. Itu menjelaskan "realtime tidak jalan, harus reload" yang mengabaikan semua perbedaan Docker. Versi baru memakai `(string $message, string $chan)`, dan error di dalam callback tidak lagi diam: `RedisException::getPrevious()` dicatat sebagai `Log::warning`.

Tes `test_callback_subscribe_menerima_dua_argumen_dan_meneruskan_pesan` memakai fake koneksi yang membungkus callback dengan `fn (string $message) => $callback($message, $channels[0])`, sehingga callback controller dipanggil persis dua argumen seperti wrapper asli. Kontrak vendor tidak ikut diuji (fake meniru, tidak memanggil Laravel), tetapi kontrak itu stabil dan kasus 7 (integrasi) menutupnya begitu dijalankan di container.

### Catch-up DB, Last-Event-ID, dan dedup

Urutan per putaran: catch-up dulu, baru `subscribe`. Urutan ini penting: pesan yang dibuat sebelum SUBSCRIBE terdaftar tertangkap catch-up putaran berikutnya (maksimal satu tick, 5 dtk), pesan sesudahnya tertangkap Pub/Sub. Cursor awal adalah `Last-Event-ID` (UUID valid dan milik user) atau `now()`. Karena kolom `created_at` bertipe `timestamp(6)` dan model memakai `$dateFormat = 'Y-m-d H:i:s.u'`, format string mikrodetik tidak dipotong. Batas 50 baris per putaran dan maju kursor per baris menjaga beban dan tidak menghilangkan baris sisa (diambil di tick berikutnya).

`$seen` (maks 100 id, FIFO) dipakai bersama oleh jalur Pub/Sub dan catch-up, sehingga pesan yang sama tiba sekali. Kasus yang terlepas dari `$seen` (lebih dari 100 id) tetap aman karena cursor sudah maju melewatinya. Beban: satu query terindeks morph + satu koneksi Redis baru per 5 dtk per tab; README mendokumentasikan batas `pm.max_children` terhadap `max_connections` MySQL.

Satu celah perilaku: bila `subscribe()` melempar seketika dan berulang, tidak ada jeda antar putaran (lihat Issues 1).

### useNotifications.js: timer, dedup, dan navigasi SPA

Tidak ada timer yang bisa ganda: `connectGuard` di-clear sebelum diset ulang, `reconnectTimer` dijaga `if (!reconnectTimer)`, `safetyTimer` dijaga `if (!safetyTimer)`, `pollTimer` dijaga di `startPolling`. `teardown()` membersihkan semuanya dan melepas listener; `pagehide`/`beforeunload` sudah ada sebelumnya. `onerror` pada status CLOSED (mis. 502 saat deploy, respons non-event-stream) menutup lalu membuka ulang manual setelah 15 dtk, yang menutup celah "EventSource tidak reconnect sendiri". `handleSignal` kini melakukan dedup berdasar id sebelum efek samping (`router.reload`), dan `pollOnce` memakai `signalSeq` agar hasil fetch lama tidak menimpa sinyal baru. Proyek tidak punya framework tes JS; perilaku ini hanya diverifikasi lewat `npm run build` (lulus) dan baca kode, belum di browser.

### Konfigurasi database/app

`database.redis.notifications.timeout=2` dan `read_timeout=3` membatasi `publish()` sinkron di request (maksimal sekitar 5 dtk saat Redis tidak merespons) dan tidak memengaruhi stream yang menimpa `OPT_READ_TIMEOUT` ke 5 dtk setelah `connect()`.

### Docker: .dockerignore, Dockerfile, nginx, php-fpm, queue

`.dockerignore` mempertahankan `docker/**`, `composer.*`, `package*.json`, `vite.config.js`, `resources`, `public` (tanpa hot/build). Kerangka `.gitignore` di `storage/**` dan `bootstrap/cache` semuanya ada di repo, jadi `COPY . .` lalu `chown/chmod` tidak gagal; `mkdir -p` menjaganya lagi. Menghapus `.env` dari image adalah perbaikan keamanan (sebelumnya rahasia ikut tertanam) dan aman karena env runtime datang dari `env_file`, dan php-fpm meneruskannya (`clear_env = no` di `docker.conf` image, dicek via `php-fpm -tt` di container dev).

Dua Dockerfile punya stage `ziggy` identik sehingga hash aset sama; drift antar dua build Vite diterima di plan (D10). Location SSE di nginx menyalin empat header keamanan karena `add_header` di level location membuang yang dari server. `queue` dengan `user: www-data` mencegah `laravel.log` milik root di volume bersama; README mendokumentasikan `chown` satu kali untuk volume lama dan `exec -u www-data`. README dan `.env.example` cocok dengan kode: tick 5 dtk, catch-up, heartbeat via config, pesan log yang disebut ada persis di kode, `REDIS_HOST=host.docker.internal` ditegaskan, `key:generate` diarahkan ke host (`--show`).

### Bukti verifikasi

`verification.md` jujur tentang apa yang belum diverifikasi: build nyata, isi image, e2e SSE, dan kasus 5/7. Angka 225 lulus + 2 skip konsisten dengan FEAT-001 (218 sebelumnya + 9 baru, tanpa regresi). Saya menjalankan ulang `php artisan test --filter=Notification` dan hasilnya sama (21 lulus, 2 skip, 75 assertions).

</details>

<details>
<summary>File map</summary>

- `app/Http/Controllers/NotificationStreamController.php`: signature 2 argumen, catch-up DB, dedup, `Last-Event-ID`, tick 5 dtk, seam protected untuk tes
- `app/Services/NotificationService.php`: `signalFor()` dipakai bersama publish dan catch-up
- `config/app.php`, `config/database.php`: `sse_heartbeat`, timeout publish Redis
- `resources/js/composables/useNotifications.js`: safety poll, guard CONNECTING, reopen CLOSED, dedup, teardown lengkap
- `tests/Feature/NotificationStreamTest.php` (baru), `tests/Feature/NotificationTest.php`: tes stream, docblock
- `.dockerignore` (baru), `docker/php/Dockerfile`, `docker/nginx/Dockerfile`: stage ziggy, hapus `public/hot`, kerangka storage
- `docker/nginx/default.conf`, `docker/php/php-fpm.conf`, `docker-compose.yml`: header SSE, pm 20/10/40, queue `www-data`
- `README.md`, `.env.example`: dokumentasi Redis host, proxy, kapasitas, troubleshooting
- `package-lock.json`: hanya perubahan `name` (noise, lihat Issues 4)

</details>
