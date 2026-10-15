# Verification — Sistem Notifikasi Realtime (SSE + Redis Pub/Sub)

Branch: `feat/notif-realtime-sse`
Worktree: `f:\LARAVEL\remunisasi_v2\.worktrees\notif-realtime-sse`
Iterasi: pertama (tidak ada `review.json` → implementasi dari plan/design yang DISETUJUI).

## Yang diimplementasikan (per plan.md / design.md)

Backend:
- Migrasi `database/migrations/2026_10_14_100001_create_notifications_table.php` — skema `notifications` standar Laravel dengan timestamp presisi mikrodetik (`timestamp(col, 6)`); nama mengurut setelah `2026_10_09_100002`.
- Model kustom `app/Models/DatabaseNotification.php` — `extends Illuminate\Notifications\DatabaseNotification` dengan `$dateFormat = 'Y-m-d H:i:s.u'`. Timestamps Eloquent tetap aktif (tidak di-override) sesuai invarian write-path §3.1.
- `app/Models/User.php` — override `notifications()` → `morphMany(DatabaseNotification::class, 'notifiable')->latest()`.
- `config/database.php` — koneksi Redis TERDEDIKASI `notifications` (`REDIS_NOTIFY_DB=2`, `read_timeout` = `SSE_HEARTBEAT_SECONDS`, `options.prefix => ''` agar channel literal `notif.user.{id}`).
- `app/Services/NotificationService.php` — satu-satunya penulis notifikasi: resolusi penerima via `sikep_user_id` (lewati aman bila user lokal tidak ada), persist baris (sumber kebenaran), lalu publish best-effort ke Redis (dibungkus try/catch — kegagalan Redis TIDAK pernah dilempar).
- Wiring POST-COMMIT di service layer:
  - `TaskService::create()` → notify Kabid (`task.submitted`).
  - `TaskService::update()` → notify Kabid HANYA bila `$statusLama !== STATUS_DIAJUKAN`.
  - `ReviewService::approve()/revisi()/reject()` → notify Katimker pemilik (`task.approved`/`task.revisi`/`task.rejected`).
  - `AssignmentService::createForKatimker()` → notify Katimker (`task.assigned`, "Penugasan baru menunggu bukti.").
  - URL dibangun sekali via `route(name, $task, false)` (relatif); dibaca ulang dari `data.url` di hilir (single-source §3.2).
- `app/Http/Controllers/NotificationController.php` — `index` (20 terbaru, JSON), `markRead` (otorisasi kepemilikan 403), `markAllRead`.
- `app/Http/Controllers/NotificationStreamController.php` — loop SSE deterministik: read-timeout 1 dtk + heartbeat wall-clock `$hb`, rebuild koneksi pada `\RedisException` (`Redis::purge` → reconnect), satu titik `close()` di `finally`, header `text/event-stream` + `X-Accel-Buffering: no`.
- `routes/web.php` — 4 route di grup `['auth','active-role','two-factor']`: `notifications.stream`, `notifications.index`, `notifications.read`, `notifications.read-all`.
- `app/Http/Middleware/HandleInertiaRequests.php` — shared prop lazy `notifications` (`items` 15 terbaru + `has_unread`). Mismatch 15 vs 20 (REST) disengaja (§8).

Frontend:
- `resources/js/composables/useNotifications.js` — module-singleton (guard `initialized`): EventSource survive lintas navigasi SPA; `onerror` TIDAK `close()` (hanya nyalakan polling); `onopen` matikan polling; polling fallback ~60 dtk dengan guard `document.hidden` + content-type; `visibilitychange` menyalakan polling bila SSE belum OPEN; teardown hanya pada `beforeunload`/`pagehide`. `reloadIfRelevant` bercabang pada `page.component` (LIST tidak dereference `props.tugas.id`; DETAIL bandingkan `sig.task_id === props.tugas.id`). CSRF via `decodeURIComponent(XSRF-TOKEN)` + `credentials: 'same-origin'`.
- `resources/js/Layouts/AppLayout.vue` — `init(usePage())` di `onMounted`; TIDAK teardown di `onUnmounted`.
- `resources/js/layout/AppTopbar.vue` — lonceng di `div.layout-config-menu`: `OverlayBadge severity="danger"` (titik merah tanpa angka) + `Popover` riwayat yang bisa diklik + "Tandai semua terbaca" + empty state. Gaya Sakai dipertahankan, topbar tidak di-restyle. `OverlayBadge`/`Popover` auto-import PrimeVue 4.5.5.

Infra/env:
- `docker/nginx/default.conf` — `location = /sse/notifications` SEBELUM `location ~ \.php$` (`fastcgi_buffering off`, `fastcgi_read_timeout 3600s`, `gzip off`, `X-Accel-Buffering no`, SCRIPT_FILENAME → index.php). Blok `.php` umum tidak diubah.
- `docker/php/php-fpm.conf` — `pm.max_children = 128`, `request_terminate_timeout = 3700`.
- `.env.example` — blok "Realtime notifications": `REDIS_NOTIFY_DB=2`, `SSE_HEARTBEAT_SECONDS=20`, catatan produksi host Redis + catatan dev Windows `artisan serve`.
- `docker-compose.yml` / `docker-compose.dev.yml` TIDAK disentuh (sudah fixed ke MySQL + host Redis).

## Hasil verifikasi (perintah nyata dijalankan)

Catatan lingkungan: worktree tidak membawa `vendor/`, `node_modules/`, `.env` (gitignored). Dilakukan `composer install`, `npm install`, `cp .env.example .env`, `php artisan key:generate` di worktree agar build/test bisa dijalankan. PHP lokal: 8.3.31 (constraint `^8.3` terpenuhi).

### Pint
`php vendor\bin\pint --test` pada 14 file yang diubah → **PASS (14 files, 0 pelanggaran)**.
(Selama implementasi `pint` memperbaiki 2 isu kecil: FQCN di `User::notifications()` dan ordered imports di test — sudah ter-commit bersih.)

### php artisan test
`php artisan test` → **214 passed, 4 failed (901 assertions)**.

- Tes notifikasi baru: `php artisan test --filter=Notification` → **14 passed (51 assertions)** — `NotificationTest` (11) + `NotificationServiceTest` (3).
- 4 kegagalan BUKAN disebabkan perubahan ini: semuanya tes yang mengakses database eksternal SIKEP (`sikep` connection, host `10.20.1.116`, user `ws_sikep`) yang tidak terjangkau/tidak ter-auth dari lingkungan uji ("Access denied for user 'ws_sikep'").
  - DIKONFIRMASI pre-existing: `git stash` seluruh perubahan lalu `php artisan test --filter="RecapTest|PeriodCutoffAndBinaanDetailTest|KabidReviewTest"` → **4 failed, 23 passed** (identik). Jadi baseline juga gagal 4 tes yang sama; implementasi ini tidak menambah/menghilangkan kegagalan.
  - Tes terdampak: `RecapTest > kabid melihat rekap binaan saja`, `PeriodCutoffAndBinaanDetailTest > kabid melihat semua tugas katimker binaan`, `PeriodCutoffAndBinaanDetailTest > detail tugas non diajukan...`, `KabidReviewTest > detail menyertakan peringatan bukti`.

Cakupan tes notifikasi (design §12.1): pembuatan baris untuk penerima benar (resolusi via `sikep_user_id`) pada approve/revisi/reject/submit/assign; update-dari-revisi mengirim vs update-diajukan tidak; mark-read membalik `read_at`; mark-read lintas-user → 403; mark-all-read; shared prop `has_unread` true/false via `assertInertia`; penerima tak ada → tidak error, tidak ada baris; SSE tanpa auth → redirect login. Unit: `notify()` buat baris saat user ada; lewati saat tak ada; tetap sukses saat publish Redis dilempar (facade mock throw).

### npm run build
`npm run build` → **sukses** (`✓ built in ~6.4s`), manifest `public/build/manifest.json` dihasilkan; komponen lonceng (`AppTopbar`), composable, dan `AppLayout` ter-compile tanpa error. (Catatan `[PLUGIN_TIMINGS]` di stderr hanya informasi performa plugin, bukan error.)

### route:list
`php artisan route:list --name=notifications` → keempat route terdaftar dengan controller yang benar.

## Yang BELUM diverifikasi (wajib smoke test manual terhadap Redis hidup)

Jalur SSE/Redis live tidak dapat diuji headless (loop blocking, butuh Redis + php-fpm multi-worker). Perlu diuji manual (design §12.3):
1. Dua browser (Kabid + Katimker): Kabid approve → titik merah + item muncul di lonceng Katimker tanpa refresh; bila Katimker di `TaskList`/`TaskDetail`, data ter-reload via `router.reload`.
2. `curl -N .../sse/notifications`: `: connected` lalu `: ping` tiap ~20 dtk tanpa buffering nginx.
3. Re-subscribability (AC #12): diamkan channel ≥60 dtk (≥3 `: ping`), lalu PUBLISH event nyata → baris `data: {...}` tetap terkirim (membuktikan rebuild koneksi §6.2 bekerja).
4. Matikan Redis → titik merah tetap muncul ≤60 dtk via polling; hidupkan → SSE pulih.
5. Dev Windows `php artisan serve`: SSE sengaja tidak tersambung (single-proses), app tetap responsif, titik merah via polling.
6. `redis-cli -n 2 SUBSCRIBE notif.user.<sikep_user_id>` menerima JSON literal (prefix kosong).
7. Verifikasi tampilan lonceng/Popover di mode light DAN dark (toggle topbar).

Catatan: `request_terminate_timeout`/`pm.max_children`, header nginx SSE, dan perilaku `OPT_READ_TIMEOUT` phpredis hanya tervalidasi end-to-end pada stack Docker (image php-fpm), bukan di suite otomatis.
