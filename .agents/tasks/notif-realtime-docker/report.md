# Laporan Akhir: Notifikasi Realtime (SSE) tidak jalan, harus reload

Status: perubahan sudah diterapkan di working tree dan diverifikasi (tes + rebuild Docker nyata). **BELUM di-commit dan BELUM di-push.**

## 1. Akar masalah

1. **Signature callback `subscribe` salah (penyebab utama).** `NotificationStreamController` mendaftarkan callback 3 argumen `($redis, $chan, $message)`. `PhpRedisConnection::subscribe()` milik Laravel memanggil callback dengan 2 argumen `($message, $channel)` (vendor baris ~458-463). Setiap pesan memicu `ArgumentCountError`, yang ditelan phpredis dan muncul sebagai `previous` dari `RedisException`. Controller hanya membangun ulang koneksi, jadi pesan hilang tanpa log. Terbukti di container dev (callback 3 argumen: pesan tidak pernah tiba; 2 argumen: tiba).
2. **`onopen` mematikan polling.** Di `useNotifications.js`, `EventSource.onopen` memanggil `stopPolling()`. Heartbeat membuat stream tampak "tersambung", jadi fallback polling mati dan notifikasi baru muncul setelah reload (data dibaca dari DB lewat shared prop).
3. Pemberat: celah pesan hilang saat koneksi subscribe dibangun ulang (Pub/Sub Redis tidak menyimpan pesan), dan tidak ada catch-up.

Bukan penyebab (dikesampingkan dengan bukti): buffering/gzip nginx, `output_buffering`, timeout FPM/nginx, session lock, `clear_env`, ekstensi phpredis.

## 2. Solusi celah pesan hilang yang dipilih

**Catch-up berbasis DB (tabel `notifications`) sebelum tiap `subscribe`**, bukan sekadar memperpanjang read-timeout.

- Cursor awal = `Last-Event-ID` (UUID valid dan milik user itu), jika tidak `now()`. Setiap event SSE membawa `id: <uuid>`.
- Dedup server (`$seen`, maks 100 id) dan dedup frontend berdasar id. Cursor memakai string mikrodetik (kolom `timestamp(6)`).
- Tick subscribe dinaikkan 1 dtk menjadi 5 dtk (hemat query dan koneksi); jalur normal tetap instan, pesan yang jatuh di celah pulih paling lambat sekitar 5 dtk.
- Alasan: memperpanjang read-timeout hanya memperkecil frekuensi celah, tidak menghilangkannya, dan memperlambat deteksi abort. DB adalah sumber kebenaran, jadi pengiriman menjadi at-least-once (tidak hilang permanen). Catch-up juga menutup reconnect browser (restart nginx/php-fpm, batas 3600 dtk).
- Kegagalan Redis (awal atau rebuild) = log warning, stream diakhiri dengan `retry: 5000` (bukan 500); `onerror` frontend menyalakan polling.

## 3. Berkas yang diubah

Kode dan tes:
- `app/Http/Controllers/NotificationStreamController.php` (signature 2 argumen, catch-up, dedup, `Last-Event-ID`, tick 5 dtk, seam protected untuk tes)
- `app/Services/NotificationService.php` (`signalFor()` dipakai bersama publish dan catch-up)
- `config/app.php` (`sse_heartbeat`), `config/database.php` (`redis.notifications`: `timeout=2`, `read_timeout=3`)
- `resources/js/composables/useNotifications.js` (pollOnce saat onopen, safety poll 5 menit, guard CONNECTING 10 dtk, reopen saat CLOSED, dedup, teardown lengkap)
- `tests/Feature/NotificationStreamTest.php` (baru), `tests/Feature/NotificationTest.php` (docblock)

Docker dan dokumentasi:
- `.dockerignore` (baru; `.env`, `vendor`, `public/hot`, `public/build`, dll. tidak lagi masuk image)
- `docker/php/Dockerfile`, `docker/nginx/Dockerfile` (stage `ziggy`, `rm -f public/hot`, kerangka storage), `docker/php/Dockerfile.dev`
- `docker/nginx/default.conf` (header keamanan dipertahankan di location SSE), `docker/php/php-fpm.conf` (pm 20/10/40, max_children 128)
- `docker-compose.yml` (queue `user: www-data`), `docker-compose.dev.yml`
- `README.md`, `.env.example`

Catatan: diff `README.md`, `docker-compose*.yml`, `Dockerfile.dev` besar sebagian karena perubahan working tree SEBELUM task ini (migrasi ke MySQL host, port 8085/6380), bukan dari task ini. Berkas pelacakan tugas: `.agents/tasks/notif-realtime-docker/` dan `.agents/tasks/notif-realtime-sse/` (untracked). Review: APPROVED (7 isu LOW/NIT, tidak ada yang blocker).

## 4. Hasil tes dan verifikasi nyata

Host (Windows, php 8.3 tanpa ext redis):
- `php artisan test`: 225 lulus, 2 skip (963 assertions). `npm run build`: sukses. `docker compose config -q` (prod dan dev): sukses.

Docker dev (rebuild nyata, port 8085):
- `nginx -t` sukses; `php-fpm -tt`: pm 128/20/10/40 (sebelumnya 5/5/35); ekstensi `redis`, `pcntl` ada.
- `NotificationStreamTest` dengan `REDIS_INTEGRATION=1`: **9 lulus, 27 assertions**, termasuk kasus RedisException tick dan integrasi Redis nyata.
- E2E SSE lewat nginx dengan sesi uji: header `text/event-stream` + `X-Accel-Buffering: no` + 4 header keamanan. 80 notifikasi tiap 100 ms: 80/80, 0 hilang, 0 duplikat, latensi median 5 ms, p95 10 ms. 14 notifikasi jeda acak 0-6 dtk: 14/14. Pesan tanpa publish tiba di tick berikutnya (5,12 dtk), tepat sekali. `Last-Event-ID` catch-up benar; ID tak dikenal tidak mengirim data lama.
- Ketahanan Redis (`docker stop`/`start`): stream terbuka berakhir tanpa 500; koneksi baru saat Redis mati tetap 200 lalu diakhiri; `notify()` saat Redis mati selesai ~5 dtk tanpa error; setelah Redis hidup, 10/10 tiba dan catch-up membawa notifikasi yang terjadi saat outage.
- Data uji (user 999001, sesi, notifikasi, berkas SQLite liar, `package-lock.json`) sudah dibersihkan.

Build produksi (`-p rtprodtest`, port 8091):
- Build `app` dan `nginx` sukses (stage ziggy + `npm run build`). Image tanpa `.env`, `public/hot`, `vendor/phpunit`, `tests`, `.git`, `node_modules`; ada `public/build/manifest.json` (md5 identik di kedua image), PHP 8.4 dengan `redis` dan `pcntl`. `nginx -t` dan `php-fpm -tt` sukses; queue memuat `user: www-data`. Resource uji sudah dihapus.
- `/sse/notifications` tanpa login di prod uji menghasilkan 500 karena `.env` lokal menunjuk DB 127.0.0.1 (kekhasan mesin ini, bukan cacat build).

## 5. Yang TIDAK diverifikasi (jujur)

- **Redis host Linux produksi** (bind/requirepass/firewall, `host.docker.internal` di Linux).
- **Reverse proxy/CDN** di depan port 8000 (perlu `proxy_buffering off` untuk `/sse/notifications`).
- **MySQL host produksi** dan `.env` produksi sebenarnya.
- **Browser nyata**: fallback polling saat stream diblokir, pollOnce saat onopen, refresh tab, tidak ada notifikasi ganda di panel, satu `EventSource` setelah navigasi SPA. Proyek tidak punya framework tes JS; hanya `npm run build` dan baca kode. Perilaku `docker restart sakai_nginx_dev` dengan tab terbuka juga tidak diuji.
- Queue worker produksi tidak dijalankan; beban banyak tab (`pm.max_children` vs `max_connections` MySQL) tidak diuji; celah antar-tick yang nyata (milidetik) tidak tertangkap kebetulan, pemulihannya dibuktikan lewat baris tanpa publish.
- Review Issue 1 (tanpa backoff bila `subscribe()` gagal cepat berulang) tidak diuji; catch-up lintas putaran belum punya tes khusus.

## 6. Temuan di luar cakupan (tidak diubah)

- **7 tes gagal di container dev** (`imagejpeg function is not defined`) karena GD tanpa dukungan JPEG; lulus di host. Bila produksi memproses JPEG dengan GD, perlu `libjpeg-turbo-dev` + `docker-php-ext-configure gd --with-jpeg` (JPEG di image prod tidak dicek langsung).
- **Duplikat pada burst ekstrem** (~20 notifikasi/dtk selama 20 dtk: 50 pesan terkirim dua kali, tidak ada yang hilang; frontend dedup by id). Penyebab dugaan dari pembacaan kode, belum dikonfirmasi. Tidak terjadi di bawah 100 notifikasi per stream.
- `php artisan test` di container dev memakai SQLite berkas `remunisasi_v2` di root repo (env compose menimpa `phpunit.xml`). Pertimbangkan `force="true"` di `phpunit.xml`.
- Docker Desktop sempat berhenti sendiri di tengah verifikasi dan dinyalakan lagi; tidak ada data hilang.

## 7. Langkah deploy dan tindak lanjut untuk user

1. **Review dan commit** perubahan (belum ada commit). Jangan ikutkan noise `package-lock.json` (sudah dikembalikan) atau `.agents/` bila tidak diinginkan.
2. **`REDIS_HOST` dan `REDIS_PASSWORD`** di `.env` produksi: `REDIS_HOST=host.docker.internal` (nilai `127.0.0.1` dari `.env.example` membuat publish gagal diam-diam, hanya `Log::warning`). Redis host harus listen di IP gateway Docker (atau `0.0.0.0` dengan firewall subnet Docker), set `requirepass` dan `REDIS_PASSWORD`. Uji dari container.
3. **`APP_KEY`**: `.env` tidak lagi masuk image, jadi `key:generate` di dalam container tidak berguna. Generate di host (atau `docker compose run --rm app php artisan key:generate --show`) lalu tempel ke `.env` host. Env runtime tetap dari `env_file`.
4. **Volume storage lama**: karena `queue` kini berjalan sebagai `www-data`, jalankan sekali `docker compose exec -u root app chown -R www-data:www-data /var/www/storage`. Gunakan `exec -u www-data` untuk perintah artisan.
5. **Rebuild**: `docker compose up -d --build`. Untuk dev: `docker compose -f docker-compose.dev.yml up -d --build app vite` lalu restart nginx (jangan `down -v`).
6. **Reverse proxy di host** (bila ada): tambahkan untuk `/sse/notifications` `proxy_buffering off; proxy_cache off; proxy_read_timeout 3600s; gzip off; proxy_http_version 1.1; proxy_set_header Connection "";`.
7. **Kapasitas**: 1 tab = 1 worker php-fpm (dan berpotensi 1 koneksi MySQL). Sesuaikan `pm.max_children` (128) dan `max_connections` MySQL (default 151) serta RAM (~40 MB per worker).
8. **Uji manual di browser** setelah deploy: DevTools Network filter `eventsource`; ajukan tugas dari Katimker, titik merah Kabid harus muncul <2 dtk tanpa reload; blokir stream untuk memastikan polling aktif; matikan lalu hidupkan Redis dan pastikan realtime pulih tanpa reload.
9. Opsional: tambahkan `libjpeg` pada GD bila perlu; `force="true"` di `phpunit.xml`; backoff pada loop `subscribe`.
