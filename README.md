# TUKIMAN — Aplikasi Kinerja & Insentif

Aplikasi web manajemen **kinerja harian** dan **perhitungan insentif** pegawai, terintegrasi dengan sistem kepegawaian **SIKEP** RSUD Bangil.

![Laravel](https://img.shields.io/badge/Laravel-13.x-FF2D20?style=flat-square&logo=laravel)
![Vue](https://img.shields.io/badge/Vue-3.x-42B883?style=flat-square&logo=vue.js)
![Inertia](https://img.shields.io/badge/Inertia.js-3.x-7C3AED?style=flat-square)
![PrimeVue](https://img.shields.io/badge/PrimeVue-4.x-41B883?style=flat-square)
![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat-square&logo=php)
![MySQL](https://img.shields.io/badge/MySQL-8-4479A1?style=flat-square&logo=mysql)

## Daftar Isi

1. [Tentang](#tentang)
   - [Fitur utama](#fitur-utama)
   - [Peran pengguna](#peran-pengguna)
   - [Teknologi](#teknologi)
   - [Ringkasan arsitektur](#ringkasan-arsitektur)
2. [Instalasi / Pemasangan](#instalasi--pemasangan)
   - [Prasyarat](#prasyarat)
   - [Konfigurasi `.env`](#konfigurasi-env)
   - [Opsi 1: Docker pengembangan](#opsi-1-docker-pengembangan)
   - [Opsi 2: Docker produksi](#opsi-2-docker-produksi)
   - [Opsi 3: Lokal tanpa Docker](#opsi-3-lokal-tanpa-docker)
   - [Migrasi dan seeder](#migrasi-dan-seeder)
   - [Membuat admin pertama](#membuat-admin-pertama)
   - [Build frontend](#build-frontend)
   - [Ringkasan perintah `make`](#ringkasan-perintah-make)
   - [Pemecahan masalah](#pemecahan-masalah)
3. [Dokumentasi](#dokumentasi)
   - [Struktur direktori](#struktur-direktori)
   - [Alur kerja per peran](#alur-kerja-per-peran)
   - [Notifikasi realtime (SSE)](#notifikasi-realtime-sse)
   - [Konfigurasi Docker, nginx, dan php-fpm](#konfigurasi-docker-nginx-dan-php-fpm)
   - [Keamanan](#keamanan)
   - [Pengujian](#pengujian)
   - [Perintah artisan berguna](#perintah-artisan-berguna)
   - [Dokumen terkait](#dokumen-terkait)
   - [Lisensi](#lisensi)

---

## Tentang

TUKIMAN membantu alur **penilaian kinerja harian** dan **perhitungan insentif** di lingkungan RSUD Bangil. Katimker mencatat tugas harian beserta bukti, Kabid menilai (menyetujui, merevisi, atau menolak), dan Admin mengelola master data, periode, serta mesin perhitungan insentif per pegawai per periode.

Autentikasi **tidak menyimpan password lokal**: kredensial diverifikasi langsung ke database SIKEP (read-only). User lokal hanya mirror ringan dari SIKEP untuk menyimpan peran, status aktif, dan pengaturan 2FA. Peran ditetapkan manual oleh Admin dan disimpan di database aplikasi.

### Fitur utama

- **Login via SIKEP**: verifikasi password terhadap database kepegawaian, tanpa menyimpan password lokal.
- **2FA (TOTP) wajib untuk Admin**: google2fa, QR code, dan recovery codes.
- **Captcha dan throttle login**: Cloudflare Turnstile (opsional, kosongkan kuncinya untuk menonaktifkan) dan pembatasan percobaan gagal.
- **Tugas harian Katimker**: catat tugas (jenis utama atau tambahan), unggah bukti (foto selfie, dokumen), koordinat lokasi opsional.
- **Penilaian Kabid**: setujui, revisi, atau tolak; ubah jenis tugas; beri penugasan tambahan.
- **Mesin insentif**: konfigurasi, perhitungan per pegawai per periode, plafon, finalisasi, laporan spreadsheet (XLSX) dan slip PDF.
- **Rekapitulasi kinerja**: rekap per Katimker, per binaan Kabid, dan rekap admin.
- **Notifikasi realtime (SSE)**: titik merah dan lonceng riwayat; data halaman yang sedang terbuka ikut diperbarui tanpa refresh.
- **Master data**: kegiatan, periode (dengan cut-off), pemetaan approver (Katimker ke Kabid), pengguna (tarik dari SIKEP).
- **Audit log**: jejak aktivitas administratif.
- **Dark mode dan tema**: konfigurator Sakai/PrimeVue.

### Peran pengguna

| Peran | Kemampuan |
|-------|-----------|
| **Katimker** | Mencatat tugas harian, mengunggah bukti, mengajukan ke penilai, memperbaiki tugas yang direvisi, melihat rekap dan slip insentif pribadi |
| **Kabid** | Menilai tugas Katimker binaannya (setujui, revisi, tolak), mengubah jenis tugas, memberi penugasan tambahan, melihat rekap binaan |
| **Admin** | Mengelola pengguna dan peran, periode, master kegiatan, pemetaan approver, konfigurasi dan perhitungan insentif, target individu, audit log, status integrasi SIKEP |

Peran yang dikenal aplikasi hanya `admin`, `katimker`, dan `kabid` (`App\Models\User::ROLES`). Pengguna tanpa peran atau nonaktif ditolak (fail-closed).

### Teknologi

| Teknologi | Versi | Fungsi |
|-----------|-------|--------|
| **Laravel** | 13.x (`^13.8`) | Backend framework, routing, ORM |
| **Inertia.js** | 3.x | Penghubung Laravel dan Vue (tanpa REST API terpisah) |
| **Vue 3** | 3.5+ | Frontend reaktif (Composition API) |
| **PrimeVue** | 4.5+ | Pustaka komponen UI (tema Sakai/Aura) |
| **Tailwind CSS** | 4.x | CSS utility-first |
| **Vite** | 8.x | Build tool dan HMR |
| **Ziggy** | 2.x | Named routes Laravel di JavaScript |
| **PHP** | 8.3+ (image Docker memakai 8.4) | Runtime |
| **Node.js** | 22 (image Docker) | Build frontend |
| **MySQL** | 8 | Database aplikasi |
| **Redis** | 7 (image dev) | Pub/Sub notifikasi SSE (koneksi `notifications`) |
| **dompdf** | 3.x | Slip PDF insentif |
| **openspout** | 5.x | Ekspor spreadsheet XLSX |
| **google2fa** | 3.x | TOTP 2FA |

### Ringkasan arsitektur

Aplikasi memakai **dua koneksi database** (lihat `config/database.php`):

| Koneksi | Driver | Peran | Akses |
|---------|--------|-------|-------|
| `mysql` (default) | MySQL | Database aplikasi (user lokal, tugas, insentif, notifikasi, dll.) | Baca dan tulis |
| `sikep` | MySQL | `bangil_kepegawaian`, sumber data kepegawaian SIKEP | **READ ONLY** |

Koneksi `sikep` tidak pernah ditulis: tidak dipakai migration/seeder dan model SIKEP memblokir operasi tulis. Charset mengikuti skema asli SIKEP (`latin1`). Verifikasi password SIKEP memakai skema hash lama `md5(SALT_1 . md5(password) . SALT_2)`; nilai salt dikonfigurasi lewat `.env`.

Notifikasi realtime memakai **Server-Sent Events (SSE)** dengan **Redis Pub/Sub**: setiap event menyimpan baris di tabel `notifications` (sumber kebenaran) dan mempublish sinyal ringan ke channel Redis per pengguna. Endpoint `/sse/notifications` (berjalan di php-fpm) men-subscribe channel itu dan mendorong sinyal ke browser. Bila SSE terputus, frontend jatuh ke polling. Detail ada di [Notifikasi realtime (SSE)](#notifikasi-realtime-sse).

Topologi produksi (aplikasi di Docker, MySQL dan Redis di host Linux, SIKEP di server lain):

```
┌──────────── Docker ─────────────┐      ┌──── Host Linux ────┐
│  nginx  → app (php-fpm)          │────▶ │  MySQL (3306)      │
│          queue (worker)          │────▶ │  Redis (6379)      │
└──────────────────────────────────┘      └────────────────────┘
                      │
                      └──────────────────▶  DB SIKEP (server lain, MySQL)
```

---

## Instalasi / Pemasangan

Tersedia tiga cara pemasangan. Untuk mencoba fitur notifikasi realtime, pakai Docker (opsi 1 atau 2), karena SSE membutuhkan server multi-worker (nginx + php-fpm).

### Prasyarat

| Kebutuhan | Opsi 1 (Docker dev) | Opsi 2 (Docker produksi) | Opsi 3 (lokal) |
|-----------|:-------------------:|:------------------------:|:--------------:|
| Docker Engine / Docker Desktop dengan Compose v2 | ya | ya | tidak |
| GNU Make (opsional, untuk pintasan `make`) | opsional | opsional | opsional |
| MySQL 8 | di host (lihat catatan) | di host Linux | ya |
| Redis | container `redis` | di host Linux | ya (wajib untuk SSE) |
| PHP 8.3+ dengan ekstensi `pdo_mysql`, `redis` (phpredis), `mbstring`, `bcmath`, `gd`, `exif`, `pcntl`, `openssl`, `zip` | tidak | tidak | ya |
| Composer 2.x | tidak | tidak | ya |
| Node.js 22+ dan npm 10+ | tidak | tidak | ya |

Semua opsi membutuhkan akses jaringan ke **database SIKEP** agar login dan penarikan pengguna berfungsi.

**GNU Make.** Berkas `Makefile` menyediakan pintasan untuk perintah Docker dan lokal (lihat [Ringkasan perintah make](#ringkasan-perintah-make)). Linux dan macOS sudah menyertakannya. Di Windows gunakan WSL, Git Bash, atau pasang lewat `choco install make` / `scoop install make`. Tanpa `make`, jalankan perintah `docker compose ...` yang tertulis di tabel.

### Konfigurasi `.env`

```bash
cp .env.example .env
```

Nilai penting di `.env` (lihat `.env.example` untuk daftar lengkap):

```env
APP_NAME=TUKIMAN
APP_URL=http://localhost:8000

# Database aplikasi (MySQL)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=remunisasi_v2
DB_USERNAME=root
DB_PASSWORD=

# Database SIKEP (MySQL, READ ONLY, server terpisah)
SIKEP_DB_HOST=10.20.1.116
SIKEP_DB_PORT=2651
SIKEP_DB_DATABASE=bangil_kepegawaian
SIKEP_DB_USERNAME=ws_sikep
SIKEP_DB_PASSWORD=
SIKEP_DB_CHARSET=latin1
SIKEP_DB_COLLATION=latin1_swedish_ci
SIKEP_PASS_SALT_1=        # nilai ada di komentar .env.example
SIKEP_PASS_SALT_2=
SIKEP_FOTO_BASE_URL=https://sikep.rsudbangil.pasuruankab.go.id/foto

# Session / cache / queue
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync

# Redis (pub/sub notifikasi)
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Notifikasi realtime (SSE)
REDIS_NOTIFY_DB=2            # DB Redis khusus channel pub/sub notifikasi
SSE_HEARTBEAT_SECONDS=20     # interval heartbeat koneksi SSE

# Cloudflare Turnstile (CAPTCHA login; kosongkan untuk menonaktifkan)
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
```

Catatan:

- `QUEUE_CONNECTION=sync` membuat job dijalankan langsung di request, sehingga service `queue` di Docker produksi tidak memproses apa pun. Ubah ke `database` atau `redis` bila ingin memakai worker.
- `APP_KEY` mengenkripsi secret 2FA admin. Jangan mengganti `APP_KEY` setelah 2FA dipakai, atau admin harus mendaftar ulang 2FA.

### Opsi 1: Docker pengembangan

`docker-compose.dev.yml` menjalankan lima service:

| Service | Container | Port host | Keterangan |
|---------|-----------|-----------|------------|
| `app` | `sakai_app_dev` | `9000` | PHP 8.4-FPM; source di-mount dari repo; `composer install` otomatis saat start |
| `nginx` | `sakai_nginx_dev` | `8085` | Pintu masuk aplikasi (`http://localhost:8085`) |
| `vite` | `sakai_vite_dev` | `5173` | Vite HMR; `npm install` otomatis saat start |
| `redis` | `sakai_redis_dev` | `6380` | Redis 7; antar-container tetap `redis:6379` |
| `mysql` | `sakai_mysql_dev` | `3307` | MySQL 8 container (lihat catatan di bawah) |

Langkah:

```bash
cp .env.example .env
make dev-up                                     # build dan jalankan stack
make dev-artisan CMD="key:generate"             # mengisi APP_KEY di .env
make dev-migrate                                # migrasi
make dev-artisan CMD="db:seed"                  # seeder master kegiatan
make dev-artisan CMD="app:set-admin <login>"    # admin pertama
```

Buka **http://localhost:8085**. Tanpa `make`, padanannya: `docker compose -f docker-compose.dev.yml up -d --build`, lalu `docker compose -f docker-compose.dev.yml exec app php artisan ...`.

Catatan penting:

- Secara bawaan `environment` pada service `app` dan `vite` menunjuk DB aplikasi **dan** DB SIKEP ke **MySQL di host** lewat `host.docker.internal` (port 3306, user `root`, tanpa password). Database `remunisasi_v2` dan `bangil_kepegawaian` harus sudah ada di MySQL host. Sesuaikan nilai tersebut di `docker-compose.dev.yml` bila kredensial berbeda.
- Container `mysql` (port host 3307) ikut berjalan, tetapi **tidak dipakai** oleh `app` kecuali Anda mengubah `DB_HOST` di compose menjadi `mysql` dan menyesuaikan kredensial (lihat blok `mysql` di compose).
- `REDIS_HOST` pada compose dev diset ke `redis` (container), sehingga `REDIS_HOST` di `.env` tidak berpengaruh untuk stack dev.
- Setelah mengubah `docker/nginx/default.conf`, jalankan `make dev-nginx-reload`. Bila perubahan tidak terbaca (bind mount berkas tunggal), pakai `docker compose -f docker-compose.dev.yml restart nginx`.
- Hentikan stack dengan `make dev-down`. Jangan memakai `down -v` kecuali Anda memang ingin menghapus volume `vendor`, `node_modules`, dan data MySQL dev.

### Opsi 2: Docker produksi

`docker-compose.yml` dirancang untuk pola: **aplikasi di dalam Docker**, sedangkan **MySQL dan Redis berjalan di host Linux** dan **DB SIKEP di server terpisah**. Service: `app` (php-fpm 8.4), `nginx` (port `${APP_PORT:-8000}`), dan `queue` (worker, berjalan sebagai `www-data`). Container mengakses layanan host lewat `host.docker.internal` (di-map ke gateway host lewat `extra_hosts: host-gateway`).

#### 1. Siapkan `.env` produksi

```env
APP_ENV=production
APP_DEBUG=false

DB_CONNECTION=mysql
DB_HOST=host.docker.internal      # MySQL di host Linux
DB_PORT=3306
DB_DATABASE=remunisasi_v2
DB_USERNAME=...
DB_PASSWORD=...

REDIS_HOST=host.docker.internal   # Redis di host Linux (WAJIB, bukan 127.0.0.1)
REDIS_PORT=6379
REDIS_PASSWORD=...                # sesuai requirepass di redis.conf host

SIKEP_DB_HOST=10.20.1.116         # DB SIKEP di server LAIN, bukan host.docker.internal
SIKEP_DB_PORT=2651
```

> **Jangan biarkan `REDIS_HOST=127.0.0.1` di Docker produksi.** Di dalam container, `127.0.0.1` adalah container itu sendiri. Publish notifikasi akan gagal diam-diam (hanya `Log::warning`) dan notifikasi baru muncul setelah polling atau refresh.

> **Grant MySQL:** koneksi dari container terlihat datang dari IP gateway Docker, bukan `localhost`. Pastikan user MySQL di host boleh connect dari subnet container (mis. `'app_user'@'172.%'`) dan MySQL listen di alamat yang dapat dijangkau container (bukan hanya `127.0.0.1`).

#### 2. Siapkan Redis di host Linux

Container menjangkau Redis host lewat IP gateway Docker, sehingga Redis host harus dikonfigurasi:

- **Bind:** listen di alamat yang dapat dijangkau container, mis. `bind 127.0.0.1 172.17.0.1` (IP gateway `docker0`; cek dengan `ip addr show docker0`; untuk network compose kustom, gunakan gateway network tersebut), atau `0.0.0.0` **dengan** firewall yang hanya mengizinkan subnet Docker ke port 6379.
- **Autentikasi:** set `requirepass` di `redis.conf` dan isi `REDIS_PASSWORD` di `.env`. Jangan membuka Redis tanpa password ke jaringan.
- **Uji dari container:**
  ```bash
  docker compose run --rm --entrypoint php app -r "echo (new Redis)->connect(getenv('REDIS_HOST'), 6379) ? 'konek OK' : 'gagal';"
  docker compose exec app php artisan tinker --execute="dump(Illuminate\Support\Facades\Redis::connection('notifications')->ping());"
  ```
- **Pantau:** `redis-cli -n 2 PUBSUB CHANNELS` di host menampilkan channel `notif.user.*` selama ada tab yang terbuka.

#### 3. Buat `APP_KEY`, build, dan jalankan

`.env` **tidak masuk ke image** (dikecualikan oleh `.dockerignore`); env runtime dibaca dari `env_file`. Karena itu `key:generate` di dalam container tidak berguna. Buat key lalu tempel ke `.env` di host:

```bash
make prod-key         # mencetak APP_KEY (tidak menulis berkas); tempel ke .env host
make prod-up          # docker compose up -d --build
make prod-migrate     # migrasi (--force) sebagai www-data
make prod-artisan CMD="db:seed --force"                # seeder master kegiatan
make prod-artisan CMD="app:set-admin <login>"          # admin pertama
make prod-cache       # opsional: cache config, route, view
```

Buka **http://localhost:8000** (atau `APP_PORT`). Jalankan perintah artisan sebagai `www-data` (`exec -u www-data`, sudah ditangani target `prod-*`) agar berkas di `storage/` tidak dimiliki root.

> **Volume lama:** bila volume `sakai_storage` pernah ditulis sebagai root (mis. `laravel.log` buatan `queue` atau `exec` tanpa `-u www-data`), php-fpm tidak bisa menulisnya. Perbaiki satu kali dengan `make prod-fix-perms`, setara:
> ```bash
> docker compose exec -u root app chown -R www-data:www-data /var/www/storage
> ```

#### 4. Reverse proxy di depan port 8000 (bila ada)

Bila ada reverse proxy atau load balancer (nginx host, Traefik, CDN) di depan container nginx, route SSE harus dikecualikan dari buffering dan memakai timeout panjang. Jika tidak, notifikasi baru terlihat setelah reload:

```nginx
location = /sse/notifications {
    proxy_pass http://127.0.0.1:8000;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 3600s;
    gzip off;
}
```

#### 5. Kapasitas

Satu tab browser menahan satu worker php-fpm selama stream terbuka, dan berpotensi satu koneksi MySQL. Dengan `pm.max_children = 128`, aplikasi dapat membuka hingga 128 koneksi MySQL (default `max_connections` MySQL = 151). Sesuaikan `pm.max_children` (RAM sekitar jumlah worker x 40 MB) dan `max_connections` MySQL host dengan jumlah pengguna serentak.

### Opsi 3: Lokal tanpa Docker

Pastikan ekstensi PHP `redis` (phpredis) dan `pcntl` tersedia bila ingin mencoba SSE. Tanpa ekstensi `redis`, publish notifikasi gagal dan dicatat sebagai warning; aplikasi tetap berjalan dengan fallback polling.

```bash
make install                      # composer install + npm ci
cp .env.example .env
php artisan key:generate
# sesuaikan .env (DB aplikasi, DB SIKEP, Redis), lalu:
php artisan migrate --seed
php artisan app:set-admin <login>
```

Jalankan (dua terminal):

```bash
php artisan serve     # terminal 1: backend, http://localhost:8000
npm run dev           # terminal 2: Vite HMR
```

> **Catatan dev lokal:** `php artisan serve` adalah server PHP single-proses. Dokumen desain notifikasi (`.agents/tasks/notif-realtime-sse/design.md`, bagian 10.4) mencatat bahwa satu koneksi SSE menahan proses itu sehingga seluruh app dev dapat tidak responsif selama stream terbuka, dan fitur tetap bekerja lewat fallback polling (sekitar 60 detik). Untuk menguji SSE end-to-end gunakan stack Docker (opsi 1 atau 2). Perilaku ini belum diuji ulang pada versi README ini.

### Migrasi dan seeder

```bash
php artisan migrate --seed      # lokal
make dev-migrate                # Docker dev (seeder: make dev-artisan CMD="db:seed")
make prod-migrate               # Docker produksi (seeder: make prod-artisan CMD="db:seed --force")
```

Pengguna **tidak di-seed**: akun berasal dari SIKEP (tarik lewat menu Pengguna oleh Admin). `DatabaseSeeder` hanya memanggil `ActivitySeeder` (master kegiatan) dan idempotent, aman dijalankan ulang. Migrasi tidak membuat database; buat database `remunisasi_v2` lebih dulu di MySQL.

### Membuat admin pertama

Admin pertama ditetapkan lewat Artisan command `app:set-admin` (`app/Console/Commands/SetAdmin.php`). Command mencari pengguna aktif di SIKEP (berdasarkan username, `no_absen`, atau `sikep_user_id`), membuat atau menyinkronkan user lokal, lalu menandainya admin dan mengaktifkannya.

```bash
# Jadikan admin
php artisan app:set-admin 1290040

# Cabut status admin (status aktif tidak diubah)
php artisan app:set-admin 1290040 --off
```

Setelah menjadi admin, pengguna wajib menyelesaikan **setup 2FA** saat login pertama.

### Build frontend

```bash
npm run build      # atau: make build
```

Hasil build ada di `public/build`. Pada Docker, build frontend sudah dijalankan di dalam Dockerfile (stage Node), jadi langkah ini hanya perlu untuk instalasi lokal. Saat pengembangan lokal, gunakan `npm run dev` (Vite HMR).

### Ringkasan perintah `make`

Jalankan `make` (atau `make help`) untuk melihat daftar target. Variabel: `DC_DEV` = `docker compose -f docker-compose.dev.yml`, `DC_PROD` = `docker compose -f docker-compose.yml`. Tidak ada target yang menghapus volume (`down -v`) atau mereset database.

| Target | Fungsi |
|--------|--------|
| `make help` | Tampilkan daftar target |
| **Pengembangan (Docker)** | |
| `make dev-up` | Build dan jalankan stack dev (`up -d --build`) |
| `make dev-down` | Hentikan stack dev (tanpa `-v`) |
| `make dev-restart` | Restart semua container dev |
| `make dev-logs [SERVICE=app]` | Ikuti log container dev |
| `make dev-shell` | Shell `bash` di container `app` |
| `make dev-artisan CMD="..."` | Jalankan artisan di container dev |
| `make dev-migrate` | Migrasi di container dev |
| `make dev-test` | Jalankan seluruh tes di container dev (SQLite in-memory) |
| `make dev-test-redis` | Tes integrasi SSE dengan Redis nyata (`REDIS_INTEGRATION=1`) |
| `make dev-nginx-reload` | `nginx -t` lalu reload nginx dev |
| **Produksi (Docker)** | |
| `make prod-build` | Build image produksi |
| `make prod-up` | Build dan jalankan stack produksi (`up -d --build`) |
| `make prod-down` | Hentikan stack produksi (tanpa `-v`) |
| `make prod-logs [SERVICE=app]` | Ikuti log produksi |
| `make prod-ps` | Status container produksi |
| `make prod-restart` | Restart container produksi |
| `make prod-fix-perms` | `chown -R www-data:www-data /var/www/storage` (sekali, untuk volume lama) |
| `make prod-key` | Cetak `APP_KEY` baru (`key:generate --show`) |
| `make prod-shell` | Shell `bash` di container `app` sebagai `www-data` |
| `make prod-artisan CMD="..."` | Jalankan artisan sebagai `www-data` |
| `make prod-migrate` | `migrate --force` sebagai `www-data` |
| `make prod-cache` | `config:cache`, `route:cache`, `view:cache` |
| `make prod-optimize-clear` | `optimize:clear` |
| **Lokal (tanpa Docker)** | |
| `make install` | `composer install` dan `npm ci` |
| `make build` | `npm run build` |
| `make test` | `php artisan test` |

### Pemecahan masalah

**`make: command not found` (Windows).** Pasang GNU Make (`choco install make` atau `scoop install make`), atau gunakan WSL / Git Bash. Alternatifnya, jalankan perintah `docker compose ...` yang tertulis di tabel.

**Login gagal / "Access denied for user 'ws_sikep'".** Aplikasi tidak dapat menjangkau atau meng-auth ke DB SIKEP. Cek `SIKEP_DB_*` di `.env` dan konektivitas jaringan ke server SIKEP. Dari dalam Docker, `SIKEP_DB_HOST` harus IP/host server SIKEP yang sebenarnya, **bukan** `host.docker.internal` (kecuali di stack dev, yang memang menunjuk ke MySQL host).

**Container tidak bisa konek ke MySQL/Redis host.**
- Pastikan `DB_HOST`/`REDIS_HOST` = `host.docker.internal` dan `extra_hosts: host-gateway` ada di compose.
- Pastikan MySQL/Redis host listen pada alamat yang dapat dijangkau container, dan grant MySQL mengizinkan koneksi dari subnet Docker.

**Notifikasi tidak realtime (harus reload).**
- Pastikan Redis hidup dan `REDIS_HOST`/`REDIS_NOTIFY_DB` benar. Di Docker produksi `REDIS_HOST` harus `host.docker.internal` (lihat [Opsi 2](#opsi-2-docker-produksi)).
- Pastikan nginx tidak mem-buffer route SSE (`docker/nginx/default.conf`), reverse proxy di depannya juga (`proxy_buffering off`), dan `pm.max_children` cukup.
- Lakukan [smoke test manual](#smoke-test-manual) di bawah untuk memastikan stream mengalir.
- Cek `storage/logs/laravel.log` (`docker compose exec app tail -f storage/logs/laravel.log`): pesan **"Publish notifikasi Redis gagal"** (Redis tak terjangkau dari container), **"SSE callback gagal"** (error di dalam callback subscribe), atau **"SSE: koneksi Redis gagal"** (stream berakhir; browser akan reconnect).
- Cek channel aktif di Redis: `redis-cli -n 2 PUBSUB CHANNELS` harus memuat `notif.user.<sikep_user_id>` selama tab terbuka.

**`laravel.log` atau `storage/` tidak bisa ditulis (permission denied).** Volume `sakai_storage` kemungkinan dimiliki root. Jalankan `make prod-fix-perms`, dan selalu jalankan perintah artisan di produksi dengan `-u www-data` (target `prod-artisan`/`prod-migrate` sudah melakukannya).

**Tes gagal di container dev: `imagejpeg function is not defined`.** Ekstensi GD pada image dev dibangun tanpa dukungan JPEG (`libpng-dev` saja), sehingga tes yang memakai `UploadedFile::fake()->image('x.jpg')` gagal di container tetapi lulus di host. Jalankan tes tersebut di host (`make test`) atau tambahkan `libjpeg-turbo-dev` dan `docker-php-ext-configure gd --with-jpeg` sebelum `docker-php-ext-install gd` di `docker/php/Dockerfile.dev`. Dukungan JPEG di image produksi belum diperiksa langsung.

**Berkas SQLite bernama `remunisasi_v2` muncul di root repo.** Terjadi bila `php artisan test` dijalankan di container dev tanpa menimpa `DB_DATABASE` (env compose mengalahkan `phpunit.xml`). Gunakan `make dev-test`, yang memaksa `DB_DATABASE=:memory:`.

**Unggah bukti gagal / HTTP 413 / "Gagal mengunggah".** Aplikasi memvalidasi foto selfie maksimal 5 MB dan dokumen/bukti maksimal 10 MB (`app/Http/Requests/TaskDraftRequest.php`). Konfigurasi di repo ini tidak mengatur `client_max_body_size` nginx maupun `upload_max_filesize`/`post_max_size` PHP, sehingga default nginx (1 MB) dan PHP (2 MB) berlaku. Naikkan batas tersebut bila perlu; ini belum diuji.

**`/sse/notifications` mengembalikan 500 di build produksi lokal.** Biasanya `.env` masih menunjuk DB `127.0.0.1` (dari mesin dev) sehingga membaca tabel `sessions` gagal di dalam container. Gunakan `.env` produksi yang benar (`DB_HOST=host.docker.internal`).

**Halaman putih / blank setelah login.**
```bash
php artisan optimize:clear
npm run dev     # pastikan Vite berjalan saat pengembangan lokal
```

**`Route [xxx] not defined`.** Ziggy atau cache route belum termuat ulang:
```bash
php artisan route:clear
```

---

## Dokumentasi

### Struktur direktori

```
app/
  Console/Commands/      # SetAdmin (app:set-admin)
  Http/
    Controllers/         # Auth, Admin, Kabid, Katimker, Notification*, Recap, Dashboard, Attachment
    Middleware/          # EnsureRole, EnsureActiveRole, EnsureTwoFactor, HandleInertiaRequests
    Requests/            # validasi form (mis. TaskDraftRequest)
  Models/                # User, TaskReport, TaskAttachment, Period, Activity, ApproverMapping,
                         # Incentive*, IndividualTarget, AuditLog, DatabaseNotification
  Models/Sikep/          # model read-only untuk database SIKEP
  Policies/              # TaskReportPolicy, TaskAttachmentPolicy, ApproverMappingPolicy
  Services/              # TaskService, ReviewService, AssignmentService, NotificationService,
                         # IncentiveCalculatorService, IncentiveReportService, IncentiveStatementService,
                         # RecapService, AttendanceService, TargetResolver, AuditLogger, TurnstileVerifier
  Services/Sikep/        # SikepAuthService, RoleResolver
  Services/TwoFactor/    # TwoFactorService
config/                  # termasuk redis.notifications (database.php) dan sse_heartbeat (app.php)
database/                # migrations, seeders (ActivitySeeder)
docker/
  nginx/                 # Dockerfile, default.conf
  php/                   # Dockerfile, Dockerfile.dev, php-fpm.conf
docs/                    # PRD, keputusan desain MVP, rekap rapat, Perdir
resources/js/
  Pages/                 # halaman Inertia: Admin, Auth, Kabid, Katimker, Rekap, UIKit, Pages
  Layouts/, layout/      # layout aplikasi dan topbar/sidebar (Sakai)
  Components/, composables/, lib/   # composable useNotifications ada di composables/
routes/web.php           # seluruh rute web
tests/                   # Feature dan Unit (PHPUnit)
.agents/tasks/           # artefak desain, rencana, review, dan verifikasi per fitur
Makefile                 # pintasan perintah (lihat Ringkasan perintah make)
docker-compose.yml       # produksi (app, nginx, queue)
docker-compose.dev.yml   # pengembangan (app, nginx, vite, redis, mysql)
```

### Alur kerja per peran

Semua rute di bawah berada dalam grup middleware `auth`, `active-role`, `two-factor` (`routes/web.php`); rute per peran dilindungi middleware `role:<peran>`.

**Katimker** (`/katimker/...`)
- `tugas`: daftar, buat, lihat, ubah, hapus tugas dan lampiran.
- `rekap` dan `rekap/slip`: rekap pribadi dan slip insentif PDF.

**Kabid** (`/kabid/...`)
- `penilaian`: daftar dan detail tugas binaan; aksi `setujui`, `revisi`, `tolak`, dan ubah `jenis`.
- `penugasan`: memberi penugasan tambahan ke Katimker.
- `target-individu`: menetapkan target individu.
- `rekap` dan `rekap/{katimker}/tugas`: rekap binaan dan detail tugas per Katimker.

**Admin** (`/admin/...`)
- `pengguna`: daftar pengguna, tarik pegawai dari SIKEP, ubah peran, aktif/nonaktif.
- `pemetaan`: pemetaan approver (Katimker ke Kabid, satu Katimker tepat satu Kabid).
- `periode`: kelola periode (termasuk cut-off).
- `kegiatan`: master kegiatan.
- `konfigurasi-insentif`, `hitung-insentif` (laporan, unduh, plafon, finalkan), `target-individu`.
- `rekap`, `audit`, `integrasi`.

Alur status tugas (`App\Models\TaskReport`):

```
Kabid beri penugasan tambahan ──▶ menunggu_bukti
                                       │ (Katimker lengkapi bukti)
Katimker catat tugas + bukti           ▼
        │  (ajukan)             ┌──────────────┐
        └──────────────────────▶│   diajukan   │──▶ Kabid menilai
                                └──────────────┘          │
                  ▲                                       ├── setujui ──▶ disetujui
                  │ (Katimker perbaiki, ajukan ulang)     ├── revisi  ──▶ revisi
                  └───────────────────────────────────────┤
                                                          └── tolak   ──▶ ditolak (final)
```

Setiap transisi memicu **notifikasi realtime** ke pihak terkait:

| Peristiwa | Penerima |
|-----------|----------|
| Katimker mengajukan tugas baru, atau memperbarui tugas yang berstatus revisi/menunggu bukti | Kabid penilai |
| Kabid menyetujui, merevisi, atau menolak | Katimker pemilik tugas |
| Kabid/Admin memberi penugasan tambahan | Katimker |

Aturan bukti yang terverifikasi dari kode: foto selfie (JPEG/PNG, maks 5 MB) dan dokumen (pdf, doc, docx, xls, xlsx, maks 10 MB); durasi dicatat dalam jam (0-24) dan menit (0-59); koordinat (`lat`, `lng`, `akurasi`) opsional. Detail aturan bisnis lengkap ada di [dokumen terkait](#dokumen-terkait).

### Notifikasi realtime (SSE)

Perubahan status tampil **tanpa refresh manual**:

- **Titik merah** di lonceng topbar saat ada notifikasi belum dibaca (tanpa angka).
- **Panel lonceng** berisi riwayat; setiap item dapat diklik untuk menandai terbaca sekaligus membuka halaman terkait (tugas/penilaian).
- **Halaman idle ikut ter-update**: saat ada perubahan relevan, data halaman di-reload otomatis lewat Inertia partial reload.

#### Cara kerja

Setiap event notifikasi (a) menyimpan baris di tabel `notifications` (sumber kebenaran riwayat) dan (b) mempublish sinyal ringan ke channel Redis per pengguna, `notif.user.{sikep_user_id}`, lewat koneksi Redis terdedikasi `notifications` (DB Redis dari `REDIS_NOTIFY_DB`, tanpa prefix agar nama channel literal). Endpoint **SSE** `/sse/notifications` (`NotificationStreamController`, berjalan di php-fpm) men-subscribe channel tersebut dan mendorong sinyal ke browser. Browser memakai `EventSource` native dengan **fallback polling sekitar 60 detik** bila SSE terputus, sehingga titik merah tetap muncul meski Redis/SSE bermasalah.

Endpoint REST pendukung: `GET /notifications`, `POST /notifications/{notification}/read`, `POST /notifications/read-all`.

#### Ketahanan (pesan tidak hilang)

Pub/Sub Redis tidak menyimpan pesan, sehingga pesan yang di-publish saat koneksi subscribe sedang dibangun ulang bisa terlewat. Karena itu:

- Setiap event SSE membawa `id: <uuid>` notifikasi. Sebelum tiap siklus subscribe, server melakukan **catch-up** satu query terindeks ke tabel `notifications` (baris milik pengguna yang lebih baru dari kursor) dan mengirim yang tertinggal. Pesan yang sama dari Pub/Sub dan catch-up dideduplikasi, jadi tiba satu kali.
- Saat browser reconnect (restart nginx/php-fpm, batas 3600 detik), `EventSource` otomatis mengirim header `Last-Event-ID`; server melanjutkan dari notifikasi tersebut (hanya bila id valid dan milik pengguna itu).
- Siklus subscribe berdurasi **5 detik** (konstanta `TICK_SECONDS` di `NotificationStreamController`): jalur normal tetap instan, pesan yang jatuh di celah pulih paling lambat sekitar 5 detik. Heartbeat (`: ping`) dikirim tiap `SSE_HEARTBEAT_SECONDS` (default 20 detik, dibaca lewat `config('app.sse_heartbeat')`).
- Frontend (`resources/js/composables/useNotifications.js`): begitu stream terbuka, daftar disegarkan sekali (`pollOnce`); ada **safety poll tiap 5 menit** dan penyegaran saat tab kembali terlihat; bila stream tak kunjung terbuka dalam 10 detik atau ditutup browser (mis. respons 502 saat deploy), polling menyala dan stream dibuka ulang otomatis.
- Kegagalan Redis saat awal atau saat membangun ulang koneksi dicatat sebagai warning dan stream diakhiri dengan `retry: 5000` (bukan HTTP 500).

> Publish Redis bersifat **best-effort**: bila Redis tidak tersedia, baris notifikasi tetap tersimpan dan request tidak pernah gagal (kegagalan hanya dicatat `Log::warning` di `storage/logs/laravel.log`). Publish dibatasi timeout singkat (connect 2 detik, baca 3 detik) agar tidak menahan request. Riwayat tetap konsisten; hanya sifat "instan" yang berkurang (jatuh ke polling).

Hasil verifikasi nyata di Docker dev (lihat `.agents/tasks/notif-realtime-docker/docker-verification.md`): 80 notifikasi per 100 ms tiba 80/80 tanpa duplikat dengan latensi median 5 ms. Pada burst ekstrem (sekitar 20 notifikasi/detik selama 20 detik) tidak ada pesan hilang, tetapi sebagian terkirim dua kali; frontend mendeduplikasi berdasarkan id.

#### Smoke test manual

1. **Header stream** (butuh cookie sesi login; ganti `<nama_sesi>` dan `<nilai>`):
   ```bash
   curl -N -i -H "Cookie: <nama_sesi>=<nilai>" http://localhost:8085/sse/notifications
   ```
   Harus segera menampilkan status 200, `Content-Type: text/event-stream`, `X-Accel-Buffering: no`, lalu `retry: 5000` dan `: connected`. Selanjutnya muncul `id:`/`data:` saat ada notifikasi dan `: ping` sebagai heartbeat. Bila tertahan tanpa output, ada buffering di jalur (proxy/CDN). Untuk produksi ganti port menjadi `8000` (atau `APP_PORT`).
2. **Browser:** buka DevTools, tab Network, filter `eventsource`. Ajukan tugas sebagai Katimker; titik merah Kabid harus muncul kurang dari 2 detik tanpa reload.
3. **Fallback:** blokir request stream; polling harus aktif dan titik merah tetap muncul dalam sekitar 60 detik.
4. **Ketahanan Redis:** matikan lalu hidupkan Redis; realtime harus pulih tanpa reload, dan notifikasi yang terjadi saat Redis mati muncul lewat catch-up.
5. **Channel Redis:** `redis-cli -n 2 PUBSUB CHANNELS` harus memuat `notif.user.<sikep_user_id>` selama tab terbuka.

Langkah 2 sampai 4 pada browser nyata belum pernah diuji otomatis (proyek tidak memiliki framework tes JavaScript); verifikasi sisi server ada di dokumen verifikasi di atas.

### Konfigurasi Docker, nginx, dan php-fpm

**Compose produksi (`docker-compose.yml`)**

| Service | Container | Keterangan |
|---------|-----------|------------|
| `app` | `sakai_app` | php-fpm 8.4; `env_file: .env`; volume `sakai_storage` di `/var/www/storage`; `APP_ENV=production`, `APP_DEBUG=false` |
| `nginx` | `sakai_nginx` | Port `${APP_PORT:-8000}` ke 80; menyajikan aset hasil build; `sakai_storage` read-only |
| `queue` | `sakai_queue` | `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`; berjalan sebagai `www-data` |

MySQL, Redis, dan DB SIKEP **tidak** berjalan sebagai container pada compose produksi. `extra_hosts: host.docker.internal:host-gateway` ada pada `app` dan `queue`. Berkas bukti tugas ada di volume `sakai_storage` (bukan di MySQL); sertakan volume ini dalam rencana cadangan Anda dan jangan menghapusnya (`down -v`).

**Image**
- `docker/php/Dockerfile` (multi-stage): stage `ziggy` (menyediakan `vendor/tightenco/ziggy` untuk build Vite), stage `node_builder` (Node 22, `npm ci`, `npm run build`), lalu runtime `php:8.4-fpm-alpine` dengan ekstensi `pdo_mysql`, `mbstring`, `exif`, `pcntl`, `bcmath`, `gd`, `zip`, `opcache`, dan `redis` (phpredis). OPcache produksi dengan `validate_timestamps=0` (rebuild image untuk menerapkan perubahan kode).
- `docker/nginx/Dockerfile`: stage yang sama untuk membangun aset, runtime `nginx:1.25-alpine`; hash aset identik dengan image `app`.
- `docker/php/Dockerfile.dev`: PHP 8.4-FPM plus Node untuk Vite HMR, `validate_timestamps=1`.
- `.dockerignore` mengecualikan `.env`, `vendor`, `node_modules`, `public/hot`, `public/build`, `tests`, `docs`, `.git`, dan `.agents` dari konteks build, sehingga `.env` tidak pernah masuk image.

**nginx (`docker/nginx/default.conf`)**
- `location = /sse/notifications` ditempatkan **sebelum** `location ~ \.php$`: `fastcgi_buffering off`, `fastcgi_read_timeout 3600s`, `gzip off`, header `X-Accel-Buffering: no`, dan `SCRIPT_FILENAME $document_root/index.php` eksplisit (route ini dirutekan Laravel lewat `index.php`).
- Header keamanan (`X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`, `Referrer-Policy`) diulang di dalam location SSE, karena `add_header` di dalam location membuang `add_header` level server.
- Aset statis di-cache 1 tahun (`immutable`); berkas tersembunyi (`/\.`) ditolak; gzip aktif untuk teks/JSON.

**php-fpm (`docker/php/php-fpm.conf`)**
- `pm = dynamic`, `pm.max_children = 128`, `start_servers = 20`, `min_spare_servers = 10`, `max_spare_servers = 40`, `pm.max_requests = 500`.
- `request_terminate_timeout = 3700` (sedikit di atas `fastcgi_read_timeout` 3600 detik).
- Satu tab browser = satu worker permanen; lihat catatan kapasitas di [Opsi 2](#opsi-2-docker-produksi). Pemisahan pool khusus SSE tercatat sebagai peningkatan opsional (desain bagian 10.2).

### Keamanan

- **Autentikasi SIKEP:** password tidak disimpan lokal; pesan gagal login generik (tidak membedakan user tidak ada dan password salah). Koneksi SIKEP read-only.
- **Throttle login:** pembatasan percobaan gagal per akun+IP (5) dan per IP (20) dalam jendela 15 menit (`LoginController`), ditambah verifikasi **Cloudflare Turnstile** bila kunci diisi.
- **2FA TOTP wajib untuk Admin:** middleware `EnsureTwoFactor` memaksa admin yang belum konfirmasi menuju `/two-factor/setup` pada setiap request; admin yang sudah konfirmasi tidak dapat membuka ulang halaman setup. Secret dan recovery codes disimpan terenkripsi (`Crypt`, terikat `APP_KEY`). Rute challenge/setup dibatasi limiter `two-factor` (5 per menit). Desain lengkap: `.agents/tasks/admin-2fa/design.md`.
- **Otorisasi:** middleware `role:<peran>` pada grup rute, `active-role` (pengguna nonaktif atau tanpa peran di-logout), dan policy untuk tugas, lampiran, dan pemetaan approver. Bukti disimpan di disk `local` (private) dan diakses lewat `/bukti/{attachment}` dengan pemeriksaan policy, bukan URL publik.
- **Audit log:** perubahan penting administratif dicatat di tabel `audit_logs`.
- **Rahasia:** `.env` tidak masuk image Docker. Redis host wajib memakai `requirepass` dan jangan dibuka ke jaringan tanpa firewall. Channel SSE ditentukan dari pengguna yang terautentikasi, sehingga pengguna tidak dapat menyadap channel orang lain.
- **Header keamanan nginx:** lihat bagian konfigurasi nginx di atas.

### Pengujian

```bash
php artisan test                        # seluruh suite (host)
php artisan test --filter=Notification  # contoh: hanya tes notifikasi
make dev-test                           # di container dev (SQLite in-memory)
make dev-test-redis                     # tes SSE dengan Redis nyata di container dev
```

- Lingkungan uji memakai SQLite in-memory, queue `sync`, cache `array`, dan session `array` (`phpunit.xml`).
- Beberapa tes memakai koneksi SIKEP yang disiapkan lewat `tests/Feature/Concerns/SetsUpSikepConnection.php`; bila ada tes yang gagal karena koneksi SIKEP, periksa lingkungan uji Anda.
- `NotificationStreamTest` memiliki kasus integrasi Redis nyata yang dilewati kecuali `REDIS_INTEGRATION=1` (target `make dev-test-redis` mengaturnya, dan butuh ekstensi `redis` serta `pcntl` seperti di container dev). Redis harus dapat dijangkau.
- Di container dev, tujuh tes yang memakai `UploadedFile::fake()->image('x.jpg')` gagal karena GD tanpa dukungan JPEG (lihat [Pemecahan masalah](#pemecahan-masalah)).
- Hasil terakhir yang tercatat di host (`.agents/tasks/notif-realtime-docker/report.md`): 225 lulus, 2 dilewati; `npm run build` sukses. Di container dev, `NotificationStreamTest` dengan `REDIS_INTEGRATION=1`: 9 lulus.

Lint dan format (Laravel Pint terpasang sebagai dev dependency; belum ada target `make`-nya):

```bash
./vendor/bin/pint          # perbaiki gaya kode
./vendor/bin/pint --test   # cek tanpa mengubah
```

### Perintah artisan berguna

```bash
# Pengembangan lokal
php artisan serve
npm run dev

# Database
php artisan migrate
php artisan migrate:status
php artisan migrate:fresh --seed     # HAPUS semua tabel lalu buat ulang; hanya untuk lingkungan dev

# Admin dan integrasi
php artisan app:set-admin <login>
php artisan app:set-admin <login> --off

# Cache dan optimasi
php artisan optimize:clear
php artisan route:list --path=sse    # periksa rute SSE
```

Untuk Docker, gunakan `make dev-artisan CMD="..."` atau `make prod-artisan CMD="..."`. Pantau log produksi dengan `make prod-logs`.

### Dokumen terkait

Dokumen produk dan desain di `docs/`:

| Berkas | Isi |
|--------|-----|
| [`docs/PRD-aplikasi-kinerja-insentif.md`](docs/PRD-aplikasi-kinerja-insentif.md) | PRD: tujuan, ruang lingkup, status laporan, aturan bisnis |
| [`docs/MVP-keputusan-desain.md`](docs/MVP-keputusan-desain.md) | Keputusan desain MVP: dua koneksi DB, peran, model data, alur status |
| [`docs/rekapitulasi-kinerja-dan-insentif.md`](docs/rekapitulasi-kinerja-dan-insentif.md) | Rekap rapat: bobot penilaian, kriteria tugas, bukti, hierarki persetujuan |
| [`docs/Perdir_RSUD_Bangil_186_2026.md`](docs/Perdir_RSUD_Bangil_186_2026.md) | Peraturan Direktur RSUD Bangil No. 186/2026 |

Artefak per fitur di `.agents/tasks/` (desain, rencana, review, verifikasi):

| Folder | Topik |
|--------|-------|
| [`notif-realtime-docker`](.agents/tasks/notif-realtime-docker/report.md) | Perbaikan notifikasi realtime dan Docker: laporan akhir (akar masalah, langkah deploy), [`verifikasi Docker`](.agents/tasks/notif-realtime-docker/docker-verification.md) |
| [`notif-realtime-sse`](.agents/tasks/notif-realtime-sse/design.md) | Desain notifikasi SSE + Redis Pub/Sub |
| [`admin-2fa`](.agents/tasks/admin-2fa/design.md) | Desain 2FA TOTP wajib untuk Admin |
| [`role-manual-sikep`](.agents/tasks/role-manual-sikep/design.md) | Peran ditetapkan manual, lepas dari jabatan SIKEP |
| `mesin-insentif` | Rencana, review, dan verifikasi mesin insentif |
| `fix-upload-bukti-tugas`, `fix-upload-bukti-tugas-v2` | Perbaikan unggah bukti tugas |
| `login-redesign-and-employee-photo` | Desain ulang login dan foto pegawai |
| `penilaian-ui-dan-rename-tukiman` | UI penilaian dan penamaan TUKIMAN |

### Lisensi

Proyek berlisensi MIT (lihat `composer.json`). Template UI dasar: [Sakai Vue](https://github.com/primefaces/sakai-vue) oleh PrimeFaces (MIT).
