# Verifikasi Docker Nyata (langkah docker-verify)

Lingkungan: Windows + PowerShell, Docker 29.6.2 (Docker Desktop), Compose v5. Tanpa commit/push. `.env` tidak dibaca (hanya nama variabel). Stack lain (pqms-*, sikep_v2-*, simrs_profile_app) tidak disentuh. Tidak ada `down -v` pada stack dev.

Kejadian di tengah langkah: Docker Desktop (WSL `docker-desktop`) berhenti sendiri setelah rebuild pertama. Saya menyalakannya lagi (`Docker Desktop.exe`), semua container proyek lain pulih lewat restart policy, lalu `docker compose -f docker-compose.dev.yml up -d` untuk stack dev. Tidak ada data yang hilang (volume tidak disentuh).

## 4.1 Stack dev (port 8085)

| Langkah / perintah | Hasil nyata | Status |
|---|---|---|
| `docker compose -f docker-compose.dev.yml up -d --build app vite` | Image `remunisasi_v2-app` dan `-vite` terbangun, container dibuat ulang | LULUS |
| `exec nginx nginx -t` lalu `restart nginx` | "syntax is ok / test is successful"; nginx Up | LULUS |
| `exec app php-fpm -tt` | `pm.max_children=128, start_servers=20, min_spare=10, max_spare=40`; "test is successful" (sebelumnya 5/5/35) | LULUS |
| Ekstensi di container dev | `redis`, `pcntl`, `posix`, `pdo_sqlite` ada | LULUS |
| `exec app php artisan test` (seluruh suite) | 219 lulus, 1 skip, **7 gagal** (913 assertions). Ketujuh gagal: `LogicException: imagejpeg function is not defined` (Assignment/KatimkerSubmit/KatimkerTask: `UploadedFile::fake()->image('x.jpg')`). Tidak terkait notifikasi (lihat Temuan A) | 7 GAGAL, tidak terkait |
| `exec -e REDIS_INTEGRATION=1 app php artisan test --filter=NotificationStreamTest` | **9 lulus, 27 assertions** termasuk kasus 5 (RedisException tick -> rebuild) dan kasus 7 (integrasi Redis nyata + pcntl_fork), 12 dtk | LULUS |
| `exec app php artisan test --filter=Notification` (tanpa flag) | 22 lulus, 1 skip (integrasi, wajar tanpa flag) | LULUS |
| Host: `php artisan test` | 225 lulus, 2 skip (963 assertions) | LULUS |
| Host: `npm run build` | `built in 923ms` | LULUS |

### E2E SSE lewat nginx dev (8085 / `nginx:80` dari dalam jaringan)
Sesi uji dibuat langsung di tabel `sessions` (driver database) untuk user uji `sikep_user_id=999001`, role `kabid`, aktif (user tersebut hanya dibuat untuk uji ini). Klien SSE mentah (socket) + notifier di proses terpisah dalam container `app`, melewati nginx.

1. Header (`curl.exe -i -N http://localhost:8085/sse/notifications` dengan cookie sesi uji): `200 OK`, `Content-Type: text/event-stream; charset=utf-8`, `X-Accel-Buffering: no`, `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `X-XSS-Protection: 1; mode=block`, `Referrer-Policy: strict-origin-when-cross-origin`, lalu `retry: 5000` dan `: connected`. LULUS.
2. Realtime nyata (`NotificationService::notify()`; hitung per id):

| Skenario | Hasil |
|---|---|
| 80 notifikasi tiap 100 ms (melewati batas tick 5 dtk) | 80 dikirim, 80 unik diterima, 0 hilang, 0 duplikat; latensi median 5 ms, p95 10 ms, maks 39 ms |
| 14 notifikasi jeda acak 0-6 dtk | 14/14, 0 hilang, 0 duplikat; latensi maks 33 ms |
| 400 notifikasi tiap 50 ms (~20 dtk, ~4 batas tick) | 400/400 unik, 0 hilang, latensi median 5 ms, maks 34-36 ms. **Ada duplikat** pada 50 pesan pertama (lihat Temuan B) |
| Pulih setelah Redis hidup lagi: 10 notifikasi | 10/10, 0 duplikat, maks 29 ms |

   Catatan: celah antar-tick hanya berukuran milidetik, jadi tidak ada pesan yang kebetulan jatuh di celah dalam uji di atas (tidak ada latensi >1 dtk). Pemulihan celah dibuktikan terpisah lewat uji "pesan hilang" di bawah.
3. Celah/publish hilang (baris notifikasi dibuat di DB TANPA publish saat stream terbuka, t=2,01 dan 3,52 dtk): kedua pesan tiba pada t=5,12 dtk (tick berikutnya), tepat satu kali. LULUS (pemulihan <=5 dtk, sesuai desain).
4. Catch-up `Last-Event-ID`: dibuat A, B, C lalu connect dengan `Last-Event-ID: A`: tiba B dan C (urut) dalam 0,1 dtk, A tidak dikirim, tidak ada pengulangan setelah tick (diamati 7 dtk). `Last-Event-ID` tak dikenal (UUID acak): tidak ada data lama terkirim. LULUS.
5. Ketahanan Redis (`docker stop sakai_redis_dev`, lalu `docker start`):
   - Stream yang sedang terbuka: server mencatat `SSE: rebuild koneksi Redis gagal, stream diakhiri` (warning), respons selesai tanpa 500.
   - Koneksi baru saat Redis mati: HTTP 200, `retry: 5000` + `: connected`, stream diakhiri setelah 4,06 dtk (waktu resolusi DNS gagal), log `SSE: koneksi Redis gagal, stream diakhiri`.
   - `notify()` saat Redis mati: selesai 5,03 dtk (bukan error), baris tersimpan 1, log `Publish notifikasi Redis gagal (diabaikan)`. Batas <=5 dtk tercapai.
   - Setelah `docker start`: klien baru langsung menerima publish (10/10); connect dengan `Last-Event-ID` sebelum outage membawa notifikasi `redis-mati` yang terjadi saat Redis mati. LULUS.
6. Pembersihan: baris notifikasi uji, baris `sessions`, dan user uji (999001) dihapus (verifikasi: user tersisa 0 untuk 999001, total user 4 seperti semula, notifikasi `uji-%` 0, sesi uji 0). Berkas `/tmp` uji di container dan temp host dihapus. Berkas SQLite liar `remunisasi_v2` di root repo (artefak tes di container, lihat Temuan C) dihapus. `package-lock.json` dikembalikan (`"name"` kembali `remunisasi_v2`; `git diff` bersih).

## 4.2 Build produksi (`-p rtprodtest`, APP_PORT=8091)

| Perintah | Hasil nyata | Status |
|---|---|---|
| `docker compose -f docker-compose.yml config -q` | exit 0 (sebelumnya) | LULUS |
| `docker compose -p rtprodtest -f docker-compose.yml build app nginx` | Sukses (stage ziggy, `npm run build`, `chown`, php-fpm.conf); image `rtprodtest-app`, `rtprodtest-nginx` | LULUS |
| Isi image `app` | Tidak ada: `public/hot`, `.env`, `vendor/phpunit`, `vendor/bin/phpunit`, `tests`, `.git`, `node_modules`. Ada: `public/build/manifest.json`, `vendor/tightenco/ziggy`, kerangka `storage/*` + `bootstrap/cache` (milik www-data, 755), PHP 8.4.26 dengan `redis` dan `pcntl`. `bootstrap/cache/packages.php` hanya berisi provider produksi (Tinker memang di `require`; tidak ada Pail/Collision/PHPUnit) | LULUS |
| Isi image `nginx` | Tidak ada `public/hot` dan `.env` | LULUS |
| Konsistensi manifest | md5 `public/build/manifest.json` kedua image **identik** (`9143aeb2...`), 77 berkas di `assets/` pada keduanya | LULUS |
| `up -d app nginx` (APP_PORT=8091) | Keduanya Up; nginx memetakan 8091->80 | LULUS |
| `curl http://localhost:8091/build/manifest.json` | 200 `application/json` | LULUS |
| `exec nginx nginx -t` | "syntax is ok / test is successful" | LULUS |
| `exec app php-fpm -tt` | pm 128 / 20 / 10 / 40; "test is successful" | LULUS |
| `exec -u www-data app php -r ...` | `ok cli` | LULUS |
| `php artisan route:list --path=sse` & config di container prod | rute `notifications.stream` ada; `app.sse_heartbeat=20`, redis `timeout=2`, `read_timeout=3`, ekstensi redis aktif | LULUS |
| `curl -i http://localhost:8091/sse/notifications` (tanpa login) | **500** (bukan 302 seperti rencana), header lokasi SSE lengkap (X-Accel-Buffering + 4 header keamanan). Penyebab di `laravel.log`: `SQLSTATE[HY000] [2002] Connection refused (Host: 127.0.0.1)` saat membaca tabel `sessions`. `.env` lokal mengarah ke DB 127.0.0.1 (dev Windows), bukan `host.docker.internal`/MySQL host produksi. Ini kekhasan `.env` mesin ini, bukan cacat build | Sesuai antisipasi plan, bukan kegagalan build |
| `docker compose ... config` untuk queue | memuat `user: www-data` | LULUS |
| Pembersihan | `down` (tanpa `-v` global), `docker volume rm rtprodtest_sakai_storage`, `docker image rm rtprodtest-app rtprodtest-nginx`; tidak ada sisa container/volume/image/network `rtprod*`; container `sakai_app/sakai_nginx` produksi tidak tersisa; stack dev tetap Up | LULUS |

## 4.3 Frontend
- `npm run build` host: sukses (`built in 923ms`). LULUS.
- (a) blokir stream -> polling 10 dtk, (b) `pollOnce` saat `onopen`, (c) refresh saat tab dibuka lagi >60 dtk, (d) tidak ada notifikasi ganda di panel, (e) satu `EventSource` setelah navigasi SPA: **TIDAK diverifikasi otomatis**. Tidak ada otomasi browser/framework tes JS di proyek. Yang terbukti hanya sisi server: stream berakhir dengan `retry: 5000` saat Redis mati (pemicu `onerror` -> polling) dan `Last-Event-ID` dipakai saat reconnect.

## Temuan
- **A (di luar cakupan, tidak diubah): 7 tes gagal di container dev** karena GD dibangun tanpa dukungan JPEG (`gd_info()['JPEG Support']=false` dicek di container dev; `Dockerfile.dev` dan `Dockerfile` prod sama-sama hanya `docker-php-ext-install gd` dengan `libpng-dev`, JPEG di image prod tidak dicek langsung). Tes yang memakai `UploadedFile::fake()->image('*.jpg')` gagal di container; di host Windows lulus. Bila aplikasi memproses/menghasilkan JPEG dengan GD di produksi, perlu `libjpeg-turbo-dev` + `docker-php-ext-configure gd --with-jpeg` (perubahan image yang tidak terkait notifikasi; saya tidak mengubahnya).
- **B (LOW, tidak diubah): duplikat di burst ekstrem.** Pada 400 notifikasi dalam ~20 dtk (~20/dtk, jauh di atas pemakaian nyata) 50 pesan pertama terkirim dua kali. Dugaan penyebab (dari pembacaan kode, hitungan per pesan, bukan per id): cursor catch-up hanya maju oleh baris yang dikirim catch-up sendiri, sedangkan baris yang sudah dikirim Pub/Sub tetap "setelah cursor"; setelah >100 pesan lain lewat, id lama terbuang dari `$seen` (SEEN_MAX=100) dan query catch-up berikutnya (limit 50) mengirimnya ulang sekali. Mekanisme ini belum dikonfirmasi dengan log per id. Tidak ada pesan hilang; frontend sudah dedup berdasar id; di bawah 100 notifikasi per stream tidak terjadi (uji 80 dan 14 notifikasi: 0 duplikat). Perbaikan bila diinginkan: majukan cursor juga saat menerima via Pub/Sub atau perbesar `SEEN_MAX`; tidak dilakukan karena dampaknya nol bagi pengguna dan di luar plan.
- **C: efek samping `php artisan test` di container dev**: `docker-compose.dev.yml` mengatur `DB_DATABASE=remunisasi_v2` di environment container sehingga `phpunit.xml` (`DB_DATABASE=:memory:` tanpa `force`) tidak menang; tes memakai SQLite berkas `remunisasi_v2` di root repo (bind mount). Berkas itu saya hapus. Data MySQL tidak tersentuh (jumlah user tetap 4). Pertimbangkan `force="true"` di `phpunit.xml` bila ingin dihindari.
- **D**: Docker Desktop sempat berhenti di tengah langkah (lihat atas); bukan akibat perubahan kode.

## TIDAK dapat diverifikasi
- Redis host Linux produksi (bind/requirepass/firewall) dan `host.docker.internal` di Linux; reverse proxy/CDN di depan port 8000; MySQL host produksi (smoke prod berhenti di 500 karena `.env` lokal mengarah ke 127.0.0.1).
- Browser nyata (4.3 a-e), perilaku `docker restart sakai_nginx_dev` dengan tab terbuka, beban banyak tab/kapasitas `pm.max_children` vs `max_connections` MySQL.
- Queue worker produksi tidak dijalankan (hanya `user: www-data` di konfigurasi).
- Celah antar-tick nyata (berukuran milidetik) tidak tertangkap secara kebetulan; pemulihannya dibuktikan lewat baris tanpa publish (tiba di tick berikutnya, 5,12 dtk).
- Review Issue 1 (tanpa backoff pada kegagalan subscribe cepat berulang) tidak diuji.

## Perubahan kode pada langkah ini
Tidak ada perubahan kode/konfigurasi. Hanya artefak ini ditulis; artefak sementara (user/sesi/notifikasi uji, berkas SQLite liar, berkas temp, resource `rtprodtest`) dibersihkan.
