# Implementation Plan — Sistem Notifikasi Realtime (SSE + Redis Pub/Sub)

Follows `design.md` (APPROVED) exactly. Do NOT re-decide architecture. All paths are absolute under the worktree `f:\LARAVEL\remunisasi_v2\.worktrees\notif-realtime-sse` — relative paths land in the parent workspace, so always use the worktree path.

Verified during exploration (do not re-discover):
- Test env (`phpunit.xml`): `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `BROADCAST_CONNECTION=null`. Redis NOT available in test → publish must be best-effort.
- No morph map enforced anywhere in `app/` (grep clean) → `notifiable_type` stores FQCN `App\Models\User`.
- Build/test commands: `php artisan test` (PHPUnit 12), `./vendor/bin/pint`, `npm run build` (Vite). No AGENTS.md / `.kiro/steering` present.
- Services currently inject ONLY `AuditLogger`. Last migration: `2026_10_09_100002_create_incentive_results_table.php`.
- Prop shapes confirmed: `Kabid/ReviewList` + `Katimker/TaskList` pass `tugas` as array/collection (no top-level `.id`); `Kabid/ReviewDetail` + `Katimker/TaskDetail` pass `tugas` as object WITH `.id`; `Dashboard` passes role blocks `katimker`/`kabid`/`admin`.
- `config/database.php` redis has global `options.prefix = {app-slug}-database-`; only `default` + `cache` connections exist.
- `nginx/default.conf` has `gzip on` global + `location ~ \.php$` with `fastcgi_buffers`; `php-fpm.conf` has `pm.max_children = 50`, no `request_terminate_timeout`.
- PrimeVue 4.5.5 with `@primevue/auto-import-resolver` → `OverlayBadge`/`Popover` auto-import (no explicit import needed).

Each item leaves the codebase buildable. Order is by dependency. Backend data layer first (steps 1-4), then service wiring (5-8), then endpoints/routes (9-11), then shared props (12), then frontend (13-15), then infra/env/docs (16-18), then tests (19), then full verification (20).

---

- [ ] 1. Create the `notifications` table migration (Laravel standard schema with microsecond timestamps).
      Create a migration whose filename sorts AFTER `2026_10_09_100002` — use the implementation date, e.g. `database/migrations/2026_10_14_100001_create_notifications_table.php`. Schema per design §3.1: `uuid('id')->primary()`, `string('type')`, `morphs('notifiable')`, `text('data')`, `timestamp('read_at', 6)->nullable()`, `timestamp('created_at', 6)->nullable()`, `timestamp('updated_at', 6)->nullable()`. No FK to users. `down()` → `dropIfExists('notifications')`.
      Files: `database/migrations/2026_10_14_100001_create_notifications_table.php`
      Verify: `php artisan migrate:fresh --env=testing` runs clean (or run step 19 tests which use `RefreshDatabase` on sqlite `:memory:` and will exercise the migration).

- [ ] 2. Create the custom notification model `App\Models\DatabaseNotification` with microsecond `$dateFormat`.
      Extend `Illuminate\Notifications\DatabaseNotification`; set `protected $dateFormat = 'Y-m-d H:i:s.u';` (same `notifications` table). Keep Eloquent timestamps ACTIVE — do NOT set `$timestamps = false`, do NOT add `const CREATED_AT`/`UPDATED_AT = null`, do NOT override `created_at` handling (design §3.1 write-path invariant R4-3). Inherited casts (`data`→array, `read_at`→datetime), UUID PK (`incrementing=false`, `keyType=string`) are preserved from parent.
      Files: `app/Models/DatabaseNotification.php`
      Verify: `./vendor/bin/pint app/Models/DatabaseNotification.php` passes; covered by step 19 ordering/creation tests.

- [ ] 3. Override `User::notifications()` to use the custom model.
      In `app/Models/User.php` add `public function notifications() { return $this->morphMany(\App\Models\DatabaseNotification::class, 'notifiable')->latest(); }`. `Notifiable` trait is already used (keep it). `unreadNotifications()` from the trait keeps working against the same table.
      Files: `app/Models/User.php`
      Verify: `./vendor/bin/pint app/Models/User.php` passes; covered by step 19.

- [ ] 4. Add the dedicated `notifications` Redis connection with empty prefix in `config/database.php`.
      Under `redis` (next to `default`/`cache`) add a `notifications` connection per design §10.3: `host`/`username`/`password`/`port` from env, `'database' => env('REDIS_NOTIFY_DB', '2')`, `'read_timeout' => env('SSE_HEARTBEAT_SECONDS', 20)`, and `'options' => ['prefix' => '']` so channel names are literal (`notif.user.{id}`), overriding the global prefix.
      Files: `config/database.php`
      Verify: `php artisan config:clear` then `php artisan tinker --execute="dd(config('database.redis.notifications.options.prefix'));"` prints empty string; `./vendor/bin/pint` clean.

- [ ] 5. Create `App\Services\NotificationService` (the single notification writer + best-effort publisher).
      Per design §5: constructor-injectable (no required deps). Method `notify(int $sikepUserId, string $type, string $message, string $url, array $extra = []): void` — resolve `User::where('sikep_user_id', $sikepUserId)->first()`; if null, `Log::info` and return (no row). Else build `$payload = array_merge(['type'=>$type,'message'=>$message,'url'=>$url], $extra)`, create the row via `$user->notifications()->create(['id'=>(string)Str::uuid(),'type'=>$type,'data'=>$payload])`, then call private `publish($sikepUserId, [...signal...])`. `publish()` wraps `Redis::connection('notifications')->publish("notif.user.{$sikepUserId}", json_encode($signal, JSON_UNESCAPED_UNICODE))` in try/catch(\Throwable) + `Log::warning` — a Redis failure must NEVER throw. Signal reuses the SAME `$url` variable (single-source rule, design §3.2); do NOT call `route()` again anywhere downstream. Include `id`, `type`, `message`, `url`, `task_id` (`$extra['task_id'] ?? null`), `created_at` (`$notification->created_at->toIso8601String()`).
      Files: `app/Services/NotificationService.php`
      Verify: `./vendor/bin/pint app/Services/NotificationService.php` passes; behavior covered by step 19 (unit + feature).

- [ ] 6. Wire `NotificationService` into `TaskService` (create + conditional update), post-commit.
      In `app/Services/TaskService.php` change constructor to `__construct(private readonly AuditLogger $audit, private readonly NotificationService $notifier)`. In `create()`: capture the transaction result into `$task`, then AFTER commit call `$this->notifier->notify($task->kabid_sikep_user_id, 'task.submitted', 'Ada tugas baru untuk dinilai.', route('kabid.penilaian.show', $task, false), ['task_id' => $task->id]);` and `return $task;`. In `update()`: `$statusLama` is already captured before the transaction; after commit, ONLY when `$statusLama !== TaskReport::STATUS_DIAJUKAN`, call `notify($task->kabid_sikep_user_id, 'task.submitted', 'Tugas diperbarui dan diajukan kembali untuk dinilai.', route('kabid.penilaian.show', $task, false), ['task_id' => $task->id])`. Keep the same return value/shape.
      Files: `app/Services/TaskService.php`
      Verify: `./vendor/bin/pint app/Services/TaskService.php`; `php artisan test --filter=KatimkerSubmit` still passes (no regression).

- [ ] 7. Wire `NotificationService` into `ReviewService` (approve/revisi/reject), post-commit.
      In `app/Services/ReviewService.php` change constructor to `__construct(private readonly AuditLogger $audit, private readonly NotificationService $notifier)`. `approve()`: capture transaction result to `$task`, after commit `notify($task->katimker_sikep_user_id, 'task.approved', 'Tugas Anda telah disetujui.', route('katimker.tugas.show', $task, false), ['task_id'=>$task->id])`, return `$task`. `revisi()`/`reject()`: notify in the PUBLIC methods (not in generic `kembalikan()`): `$task = $this->kembalikan(...); $this->notifier->notify($task->katimker_sikep_user_id, 'task.revisi'|'task.rejected', 'Tugas Anda diminta untuk direvisi.'|'Tugas Anda ditolak.', route('katimker.tugas.show', $task, false), ['task_id'=>$task->id]); return $task;`. `ubahJenis()` emits NO notification. `kembalikan()` stays generic (unchanged signature).
      Files: `app/Services/ReviewService.php`
      Verify: `./vendor/bin/pint app/Services/ReviewService.php`; `php artisan test --filter=KabidReview` still passes.

- [ ] 8. Wire `NotificationService` into `AssignmentService::createForKatimker`, post-commit.
      In `app/Services/AssignmentService.php` change constructor to `__construct(private readonly AuditLogger $audit, private readonly NotificationService $notifier)`. Restructure `createForKatimker()` so the transaction result is captured to `$task`, then after commit `$this->notifier->notify($katimkerSikepUserId, 'task.assigned', 'Penugasan baru menunggu bukti.', route('katimker.tugas.show', $task, false), ['task_id' => $task->id]);` and `return $task;`. Recipient is the `$katimkerSikepUserId` argument. `ReviewController::assignStore` method-injects the service and is NOT changed (container resolves `NotificationService` into the constructor automatically).
      Files: `app/Services/AssignmentService.php`
      Verify: `./vendor/bin/pint app/Services/AssignmentService.php`; covered by step 19 #5 via the real `kabid.penugasan.store` route.

- [ ] 9. Create `App\Http\Controllers\NotificationController` (index / markRead / markAllRead).
      Per design §7. `index(Request)`: map `$request->user()->notifications()->latest()->limit(20)->get()` to `['id','type'=>data.type??type,'message'=>data.message??'','url'=>data.url??'#','read_at'=>read_at?->toIso8601String(),'created_at'=>created_at->toIso8601String()]`; return `response()->json(['notifications'=>$items,'has_unread'=>$user->unreadNotifications()->exists()])`. Readers read back `data.url` — do NOT call `route()`. `markRead(Request, \App\Models\DatabaseNotification $notification)`: `abort_unless($notification->notifiable_id === $request->user()->id && $notification->notifiable_type === User::class, 403)` then `$notification->markAsRead()` then `response()->noContent()`. `markAllRead(Request)`: `$request->user()->unreadNotifications->markAsRead(); return response()->noContent();`.
      Files: `app/Http/Controllers/NotificationController.php`
      Verify: `./vendor/bin/pint app/Http/Controllers/NotificationController.php`; covered by step 19 #6/#7/#8.

- [ ] 10. Create `App\Http\Controllers\NotificationStreamController::stream` (SSE loop).
      Implement exactly per design §6.2: return `Symfony\Component\HttpFoundation\StreamedResponse`. In the streamed closure: `@set_time_limit(0)`, drain all output buffers once, emit `: connected`. Build `$channel = "notif.user.{$sikepUserId}"` from `$request->user()->sikep_user_id`; `$hb = (int) config('database.redis.notifications.read_timeout', 20)`. Local `$connect()` helper: `Redis::purge('notifications'); $connection = Redis::connection('notifications'); $client = $connection->client(); $client->setOption(\Redis::OPT_READ_TIMEOUT, 1); return [$connection,$client];`. Outer loop `while (! connection_aborted())` calls `$connection->subscribe([$channel], fn → $this->sse('data: '.$message))`; `catch (\RedisException)` → REBUILD via `$connect()` (do not reuse the broken handle); `catch (\Throwable)` → log debug + break. After each iteration, send `: ping` when `time()-$last >= $hb` and reset `$last`. `finally` → `$client->close()` once. Private `sse(string $line)` → `echo $line."\n\n"; @ob_flush(); @flush();`. Set headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`, `Connection: keep-alive`. Resolve the connection INSIDE the closure (not the constructor).
      Files: `app/Http/Controllers/NotificationStreamController.php`
      Verify: `./vendor/bin/pint app/Http/Controllers/NotificationStreamController.php`; auth guard covered by step 19 #11 (full streaming is manual, §12.3).

- [ ] 11. Register the four routes inside the existing auth group in `routes/web.php`.
      Inside the `Route::middleware(['auth','active-role','two-factor'])->group(...)` block (sibling to dashboard/logout), add: `GET /sse/notifications` → `NotificationStreamController@stream` name `notifications.stream`; `GET /notifications` → `NotificationController@index` name `notifications.index`; `POST /notifications/{notification}/read` → `markRead` name `notifications.read`; `POST /notifications/read-all` → `markAllRead` name `notifications.read-all`. Import both controller classes at the top. `{notification}` uses implicit binding to `App\Models\DatabaseNotification`.
      Files: `routes/web.php`
      Verify: `php artisan route:list --name=notifications` lists all four routes with the auth middleware group.

- [ ] 12. Add the `notifications` shared prop in `HandleInertiaRequests::share()`.
      Per design §8, inside the `array_merge(parent::share($request), [...])` add a lazy `'notifications' => fn () => $request->user() ? (closure)($request->user()) : null` returning `['items' => <latest 15 mapped like step 9 index, limit(15)>, 'has_unread' => $u->unreadNotifications()->exists()]`. Mapper reads back `data.url` (no `route()`). The 15-vs-20 limit difference vs the REST index is intentional (design §8 contract) — do NOT "reconcile" them.
      Files: `app/Http/Middleware/HandleInertiaRequests.php`
      Verify: `./vendor/bin/pint`; covered by step 19 #9 (`assertInertia` on `notifications.has_unread`).

- [ ] 13. Create the frontend composable `resources/js/composables/useNotifications.js` (module-singleton).
      Implement exactly per design §9.1: module-scope `es`, `pollTimer`, `initialized` guard, reactive `items`/`hasUnread`. `POLL_MS = 60000`. `csrfHeader()` reads `XSRF-TOKEN` cookie with `decodeURIComponent`. `openSse()` (guard `if (es) return`): `new EventSource(route('notifications.stream'))`, `onopen=stopPolling`, `onmessage` → `JSON.parse` in try/catch → `handleSignal`, `onerror` → `startPolling()` (NEVER `es.close()`). `handleSignal(sig)` prepends to `items` if id not present, sets `hasUnread=true`, then `reloadIfRelevant(sig)`. `reloadIfRelevant` branches on `usePage().component`: LIST (`Kabid/ReviewList`,`Katimker/TaskList`) reload by signal type only (never touch `props.tugas.id`); DETAIL (`Kabid/ReviewDetail`,`Katimker/TaskDetail`) compare `sig.task_id === props.tugas.id`; `Dashboard` always reload existing role blocks; use `router.reload({ only: [...] })` per the design table. `startPolling`/`stopPolling`/`pollOnce` with `document.hidden` guard + content-type guard + `credentials:'same-origin'`. `onVisibilityChange`: hidden → stopPolling; visible → `openSse()` if `!es`, and `startPolling()` whenever `!es || es.readyState !== EventSource.OPEN`. `init(page)` guarded by `initialized`: seed from `page.props.notifications`, `openSse()`, add `visibilitychange` + `beforeunload`/`pagehide` listeners. `teardown()` closes es, stops polling, removes listeners, resets `initialized`. `markRead(item)`/`markAllRead()` POST with `csrfHeader()`+credentials then update state; `markRead` ends with `router.visit(item.url)`. Export `items`, `hasUnread`, `init`, `markRead`, `markAllRead`, and a `relativeTime` helper (or add it in the component).
      Files: `resources/js/composables/useNotifications.js`
      Verify: `npm run build` compiles without errors (full build in step 20).

- [ ] 14. Initialize the composable in `resources/js/Layouts/AppLayout.vue`.
      In `<script setup>` import `useNotifications` and `usePage` (`@inertiajs/vue3`), call `init(usePage())` in `onMounted`. Do NOT call `teardown()`/`es.close()` in `onUnmounted` — the project uses per-page layout (verified: pages use `defineOptions({ layout: AppLayout })`, `app.js` sets no persistent layout), so `AppLayout` remounts every navigation; the module singleton + `initialized` guard keeps one EventSource per SPA session (design §9.3).
      Files: `resources/js/Layouts/AppLayout.vue`
      Verify: `npm run build` (step 20).

- [ ] 15. Add the bell + Popover to `resources/js/layout/AppTopbar.vue`.
      Per design §9.2: inside `div.layout-config-menu`, place a bell BEFORE the theme/user buttons, matching Sakai styling (do NOT restyle the topbar). Use `OverlayBadge severity="danger"` with no `value` (red dot) wrapping an icon button `pi pi-bell` when `hasUnread`, plain button otherwise; a PrimeVue `Popover` (ref `op`) listing `items` with message + relative time, unread items emphasized, a "Tandai semua terbaca" `Button link`, and an empty state "Belum ada notifikasi.". Wire `items`/`hasUnread`/`markRead`/`markAllRead` from `useNotifications`; `toggle(event)` → `op.value.toggle(event)`; `onClick(n)` → `markRead(n)` then `op.value.hide()`. `OverlayBadge`/`Popover` auto-import (PrimeVue 4.5.5) — no explicit import.
      Files: `resources/js/layout/AppTopbar.vue`
      Verify: `npm run build` (step 20).

- [ ] 16. Add the SSE-specific nginx location in `docker/nginx/default.conf`.
      Per design §10.1: add `location = /sse/notifications { ... }` BEFORE `location ~ \.php$`. Set `fastcgi_pass app:9000;`, `include fastcgi_params;`, `fastcgi_param SCRIPT_FILENAME $document_root/index.php;`, `fastcgi_param SCRIPT_NAME /index.php;`, `fastcgi_param REQUEST_URI $request_uri;`, `fastcgi_buffering off;`, `fastcgi_read_timeout 3600s;`, `gzip off;`, `add_header X-Accel-Buffering no;`. Do NOT modify the general `location ~ \.php$`. Do NOT touch `docker-compose.yml`/`.dev.yml` (already fixed to MySQL + host Redis).
      Files: `docker/nginx/default.conf`
      Verify: `nginx -t` if a container is available, else visual review against §10.1 (config-only; validated end-to-end in manual §12.3).

- [ ] 17. Raise php-fpm limits for long-lived SSE workers in `docker/php/php-fpm.conf`.
      Per design §10.2: add `request_terminate_timeout = 3700` (just above the nginx 3600s read timeout) and raise `pm.max_children` to `128` (document the per-SSE-client worker cost). Keep other `[www]` settings.
      Files: `docker/php/php-fpm.conf`
      Verify: visual review against §10.2 (config-only).

- [ ] 18. Add new env keys to `.env.example` with defaults and a short note.
      Per design §10.3, append a "Realtime notifications (SSE + Redis pub/sub)" block near the existing REDIS_* keys: `REDIS_NOTIFY_DB=2`, `SSE_HEARTBEAT_SECONDS=20`, and a commented `# REDIS_HOST=host.docker.internal` note for production (app in Docker, Redis/MySQL on host; Linux Docker needs `extra_hosts: host.docker.internal:host-gateway`). Also add a short dev note (README or inline comment) that under `php artisan serve` SSE intentionally does not connect and the ~60s polling fallback keeps the red dot working (design §10.4).
      Files: `.env.example` (and a brief note in `README.md` if present)
      Verify: `./vendor/bin/pint` not applicable; visual review that keys match `config/database.php` env names from step 4.

- [ ] 19. Add feature tests `tests/Feature/NotificationTest.php` (+ optional `tests/Unit/NotificationServiceTest.php`).
      Follow the `KabidReviewTest`/`KatimkerSubmitTest` patterns (mock `RoleResolver`, `User::factory`, `TaskReport::create`, `RefreshDatabase`, `Mockery::close()` in tearDown). Cover design §12.1: (1) approve → one `notifications` row for the Katimker User (`sikep_user_id = katimker_sikep_user_id`), `type='task.approved'`, `data.url` contains `/katimker/tugas/{id}`, `notifiable_type = User::class`; (2) revisi→`task.revisi`, reject→`task.rejected`; (3) submit via `katimker.tugas.store` → row for Kabid User, `task.submitted`, url contains `/kabid/penilaian/{id}` (needs `ApproverMapping` + active `Activity` + open period like `KatimkerSubmitTest`); (4) update from `revisi` notifies, update already-`diajukan` does NOT (`assertDatabaseCount` before/after); (5) `kabid.penugasan.store` → `task.assigned` "Penugasan baru menunggu bukti."; (6) markRead sets `read_at`; (7) markRead on another user's notification → 403, `read_at` stays null; (8) markAllRead; (9) shared prop `notifications.has_unread` true/false via `assertInertia`; (10) recipient User missing → no exception, no row, request still succeeds; (11) `notifications.stream` without auth → redirect to login (assert header `Content-Type: text/event-stream` only via a quickly-aborted request, or mark the full stream as manual). Assert set membership / counts, not intra-second ordering (design §12). Optional unit test: `notify()` creates a row when user exists, skips when absent, and still succeeds when a mocked `Redis` publish throws.
      Files: `tests/Feature/NotificationTest.php`, optional `tests/Unit/NotificationServiceTest.php`
      Verify: `php artisan test --filter=Notification` — all new tests pass.

- [ ] 20. Full verification: pint, test suite, and asset build.
      Run the project's real commands end to end and fix any failures before done.
      Files: none (verification)
      Verify: `./vendor/bin/pint` reports no violations on new/changed files; `php artisan test` passes the full suite (no regressions); `npm run build` succeeds (bell component + composable compile).

---

## Out of scope / notes for the implementer
- Do NOT touch `docker-compose.yml` / `docker-compose.dev.yml` — already fixed to MySQL + host Redis per the latest user request.
- Production compose infra alignment (removing unused postgres/redis containers, `extra_hosts`, DB driver) is infrastructure work OUTSIDE the app code — raise it with the user before touching production compose (design §10.3).
- Full SSE streaming + nginx buffering + re-subscribability after idle are verified MANUALLY (design §12.3), not in the automated suite. `php artisan serve` is single-process; SSE will not connect under it — the polling fallback is expected to carry the red dot there (design §10.4).
- `data.url` has a single source: the `$url` arg to `notify()` built with `route(name, $task, false)`. Every reader (REST index, shared prop, Redis signal) reads back `data.url` and must NEVER call `route()` again (design §3.2).
