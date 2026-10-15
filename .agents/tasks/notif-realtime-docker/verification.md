# Verifikasi Integrasi Lintas-FEAT (Iterasi 1)

Lingkungan: Windows + PowerShell, php 8.3 lokal tanpa ext redis/pcntl. Tanpa commit. `.env` tidak dibaca.

| Perintah | Hasil nyata |
|---|---|
| `php artisan test` | exit 0; **225 lulus, 2 skip** (963 assertions), 5,45 dtk. Skip: kasus 5 (RedisException tick) dan kasus 7 (integrasi) karena butuh ext redis/pcntl + `REDIS_INTEGRATION=1`. |
| `npm run build` | exit 0 (`built in 963ms`). |
| `docker compose -f docker-compose.yml config -q` | exit 0. |
| `docker compose -f docker-compose.dev.yml config -q` | exit 0. |
| `docker build --check -f docker/php/Dockerfile .` | "Check complete, no warnings found." |
| `docker build --check -f docker/nginx/Dockerfile .` | "Check complete, no warnings found." |

## Tinjauan sambungan antar-FEAT terhadap plan-final.md
- README/.env.example cocok dengan kode FEAT-001: `TICK_SECONDS` 5 dtk, heartbeat via `config('app.sse_heartbeat')` (`SSE_HEARTBEAT_SECONDS`, default 20), safety poll 5 menit, guard 10 dtk, reopen saat CLOSED. Komentar lama "TETAP 1 dtk" sudah tidak ada.
- Pesan log yang disebut README ('Publish notifikasi Redis gagal', 'SSE callback gagal', 'SSE: koneksi Redis gagal') ada persis di kode (NotificationService / NotificationStreamController).
- Docker: stage `ziggy` + `rm -f public/hot` ada di kedua Dockerfile; location SSE di default.conf memuat X-Accel-Buffering + 4 header keamanan, semua `always`; php-fpm 20/10/40 (min <= start <= max, max_children 128); queue `user: www-data`; `.dockerignore` ada.
- Tidak ada pembaca lain `database.redis.notifications.read_timeout` untuk heartbeat (sesuai findings FEAT-001).
- Catatan: diff `README.md`, `docker-compose*.yml`, `Dockerfile.dev`, `package-lock.json` yang besar sebagian berasal dari perubahan working tree SEBELUM task ini (migrasi Postgres->MySQL host, port 8085/6380), bukan dari FEAT-001/002. Tidak diubah.

## Perbaikan yang dilakukan di iterasi ini
Tidak ada; tidak ditemukan ketidaksesuaian antar-FEAT, tes gagal, atau build gagal.

## Belum terverifikasi (tugas langkah docker-verify)
Build image nyata (prod, `-p rtprodtest`, APP_PORT=8091), isi image, `php-fpm -tt` di container dev, `nginx -t`, header SSE pada sesi login, e2e SSE, serta kasus 5 & 7 `NotificationStreamTest` di container (`-e REDIS_INTEGRATION=1`). Redis host/firewall produksi dan reverse proxy host tidak dapat diverifikasi lokal.
