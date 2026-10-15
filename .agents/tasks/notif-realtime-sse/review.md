# Realtime SSE notification feature — review

The change adds a bell-with-red-dot notification system to TUKIMAN so users see status/approval changes without a manual refresh. Domain events (submit, approve, revisi/reject, assignment) each persist a `notifications` row for the correct recipient and best-effort publish a lightweight signal to a per-user Redis pub/sub channel; a Laravel SSE route streams those signals to the browser, which updates the bell and conservatively reloads the open page's props. A ~60s polling fallback carries the red dot when SSE is down. The implementation tracks the APPROVED design (`design.md`, revisi pass 4) and plan closely — recipient resolution by `sikep_user_id`, post-commit emission from the service layer, best-effort Redis wrapping, the dedicated empty-prefix Redis connection with per-tick rebuild, the module-singleton EventSource, the list-vs-detail guard, the red-dot-only bell, relative Ziggy deep links, and the correctly sorted migration are all present and faithful.

Watch for: nothing blocking. The only observations are non-blocking: SSE/Redis live behavior is unverifiable headless and remains a manual smoke test (acknowledged in design §12.3), and 4 pre-existing SIKEP-connection test failures are confirmed unrelated to this change.

**Verdict**: APPROVED

## High-level view

Recipients are resolved the right way. `NotificationService::notify()` takes a `sikep_user_id`, resolves the local `User` via `where('sikep_user_id', ...)`, and skips safely (log + return, no row) when no local user exists — so `notifiable_id` always stores the local `users.id` while identity flows through SIKEP ids. All four service call sites pass the correct id (`kabid_sikep_user_id`, `katimker_sikep_user_id`, or the assignment argument).

Emission is post-commit at the service layer. Each of `TaskService::create/update`, `ReviewService::approve/revisi/reject`, and `AssignmentService::createForKatimker` captures the transaction result into a variable and calls `notify()` after the closure returns, then returns the same task — so a notification row never rolls back with a failed core transaction and `$task->id` is always present. `TaskService::update()` re-notifies only when `$statusLama !== STATUS_DIAJUKAN`, matching the "returned to review, not an edit of an already-submitted task" rule.

Redis is genuinely best-effort. The publish is wrapped in `try/catch (\Throwable)` + `Log::warning`, so a Redis outage never propagates — proven by a unit test that mocks `Redis::connection` to throw and still asserts the row persists.

The SSE loop is defensive. A dedicated `notifications` Redis connection with `options.prefix => ''` keeps channel names literal (`notif.user.{id}`), and the stream controller rebuilds the connection (`Redis::purge` → reconnect → re-apply 1s read-timeout) on every `\RedisException`, with wall-clock heartbeats independent of phpredis timeout behavior and a single `close()` in `finally`.

The frontend owns one EventSource at module scope with an `initialized` guard; `AppLayout` only calls `init()` on mount and never tears down on unmount, so the connection survives Inertia navigations. `onerror` starts polling but never closes, preserving native reconnect. The idle-update brancher keys off `page.component` and only dereferences `props.tugas.id` on detail pages, never on list pages where `tugas` is an array.

The bell is a red-dot-only `OverlayBadge severity="danger"` (no value) over a Sakai `layout-topbar-action` button with a `Popover` history, placed before the theme/user buttons without restyling the topbar. Deep links are relative Ziggy URLs built once in the service and read back from `data.url` everywhere downstream. The migration sorts after `2026_10_09_100002`.

<details>
<summary>Issues (0)</summary>

No blocking or actionable findings. Non-blocking notes (SSE/Redis manual smoke test outstanding per design §12.3; 4 pre-existing SIKEP test failures confirmed unrelated) are informational and already documented in `verification.md`.

</details>

<details>
<summary>Details</summary>

### Recipients resolved by sikep_user_id, not User.id

`NotificationService::notify(int $sikepUserId, ...)` resolves `User::query()->where('sikep_user_id', $sikepUserId)->first()` and, on null, logs `Log::info` and returns without creating a row. The persisted row is written through `$user->notifications()->create(...)`, so `notifiable_id` carries the local `users.id` (the User PK the `Notifiable` trait uses), while every caller passes a SIKEP id: `TaskService` → `$task->kabid_sikep_user_id`, `ReviewService` → `$task->katimker_sikep_user_id`, `AssignmentService` → the `$katimkerSikepUserId` argument. The feature test `test_notifikasi_tetap_dibuat_saat_user_lokal_penerima_tidak_ada` exercises the missing-recipient path end to end: an approve with no local Katimker user still redirects, status flips to `disetujui`, and `assertDatabaseCount('notifications', 0)` holds. The authorization checks in `markRead` (`notifiable_id === $request->user()->id && notifiable_type === User::class`) rely on the no-morph-map assumption that design §1.6 pins as normative; that assumption is still true in this diff.

### Post-commit emission and the update re-notify gate

All four services follow the same shape: `$task = DB::transaction(fn () => ...)`, then `notify(...)`, then `return $task`. This keeps notification writes outside the core transaction (no rollback coupling) and guarantees `$task->id` for the relative `route(name, $task, false)` URL. `TaskService::update()` gates the re-notify on `$statusLama !== TaskReport::STATUS_DIAJUKAN`, and `test_update_dari_revisi_mengirim_notifikasi_tetapi_update_diajukan_tidak` asserts both directions against `assertDatabaseCount`: updating an already-`diajukan` task produces zero rows, updating a `revisi` task produces exactly one `task.submitted` row to the Kabid. `ReviewService::revisi/reject` notify in the public methods after `kembalikan()` (which runs and commits its own transaction), keeping `kembalikan()` generic; the `'task.reject'` string passed into `kembalikan()` is the pre-existing audit action, distinct from the `'task.rejected'` notification type set in `reject()` — not a mismatch.

### Best-effort Redis publish

```php
private function publish(int $sikepUserId, array $signal): void
{
    try {
        Redis::connection('notifications')
            ->publish("notif.user.{$sikepUserId}", json_encode($signal, JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
        Log::warning('Publish notifikasi Redis gagal (diabaikan)', [...]);
    }
}
```

The `\Throwable` catch is the broadest possible, so neither a connection failure nor a `publish` error can surface into the HTTP request. `test_notify_tetap_sukses_saat_publish_redis_gagal` mocks `Redis::connection` to throw and asserts the row still lands — confirming NFR-1 holds in the automated suite (where Redis is absent and `BROADCAST_CONNECTION=null`).

### Dedicated Redis connection and read-timeout rebuild

`config/database.php` adds a `notifications` connection next to `default`/`cache` with `'options' => ['prefix' => '']`, overriding the global `{app-slug}-database-` prefix so the publisher channel and the SSE subscriber channel are the same literal `notif.user.{id}`. `read_timeout` is sourced from `SSE_HEARTBEAT_SECONDS` and feeds the heartbeat interval `$hb`, while the subscribe loop's raw `OPT_READ_TIMEOUT` is a fixed 1s constant — the two roles are kept separate exactly as design §6.2 requires.

The stream controller's `$connect()` helper does `Redis::purge('notifications')` → `Redis::connection('notifications')` → `client()->setOption(OPT_READ_TIMEOUT, 1)`, and the outer `while (! connection_aborted())` loop re-invokes it on every `\RedisException` so the next `subscribe()` always runs on a clean command-ready socket rather than a possibly-wedged subscriber handle. Heartbeats are emitted on a wall-clock accumulator (`time() - $last >= $hb`) independent of whether phpredis throws on idle, and the connection closes exactly once in `finally`. A `\Throwable` (non-Redis) catch logs at debug and breaks. This is the deterministic loop the design settled on after prior review passes; its live behavior (real message delivery after ≥3 idle pings) is the one item that genuinely cannot be verified headless and is deferred to the manual smoke test — a reasonable boundary given the single-process test environment.

### Module-singleton EventSource surviving Inertia navigations

`useNotifications.js` holds `es`, `pollTimer`, and `initialized` at module scope. `init()` is guarded by `initialized` so it seeds state, opens SSE, and attaches `visibilitychange` + `beforeunload`/`pagehide` listeners once per SPA session; `AppLayout.vue` calls `init(usePage())` in `onMounted` and adds no `onUnmounted` teardown, so the per-page layout remount on each navigation does not churn the connection. `teardown()` (real close) is wired only to `beforeunload`/`pagehide`. `onerror` calls `startPolling()` and never `es.close()`, so native EventSource reconnect stays alive; `onopen` is the sole caller of `stopPolling()`. `onVisibilityChange` restarts polling whenever `es.readyState !== EventSource.OPEN`, closing the "no SSE and no polling" gap the prior design passes identified.

### List-vs-detail idle-update guard

`reloadIfRelevant(sig)` branches on `page.component`. `Dashboard` reloads whichever of `['katimker','kabid','admin']` props exist; `Kabid/ReviewList` reloads `['tugas']` on `task.submitted`; `Katimker/TaskList` reloads on the approve/revisi/reject/assigned set — all keyed on signal type only, never touching `props.tugas.id` (which is an array on list pages). Only `Kabid/ReviewDetail` and `Katimker/TaskDetail` compare `sig.task_id === props?.tugas?.id`. The optional chaining on `props?.tugas?.id` means even an unexpected shape degrades to a no-op rather than throwing.

### Red-dot bell, deep links, migration ordering

The bell is `OverlayBadge severity="danger"` with no `value` (a bare red dot) wrapping a `layout-topbar-action` button with `pi pi-bell`, falling back to a plain button when `!hasUnread`, placed in `div.layout-config-menu` before the theme and user buttons. The `Popover` lists `items` with message + `relativeTime`, bolds unread, offers "Tandai semua terbaca", and shows "Belum ada notifikasi." when empty — Sakai topbar styling is preserved, not restyled, and both components rely on PrimeVue 4.5.5 auto-import. Deep links are built once as relative URLs (`route(name, $task, false)`) in the services and read back from `data.url` by the REST index, the shared prop mapper, and the Redis signal — no downstream `route()` call that could reintroduce an absolute URL. The migration `2026_10_14_100001_create_notifications_table.php` sorts after the last existing migration `2026_10_09_100002`, and uses `timestamp(col, 6)` microsecond columns paired with the custom `DatabaseNotification` model's `$dateFormat = 'Y-m-d H:i:s.u'` for deterministic `latest()` ordering.

### Test coverage

`NotificationTest` (11 cases) covers approve/revisi/reject/submit/assign row creation for the correct recipient with `notifiable_type => User::class` and `data.url` substring assertions, the update re-notify gate (both directions via `assertDatabaseCount`), mark-read, cross-user mark-read → 403 with `read_at` untouched, mark-all-read, the shared-prop `has_unread` true/false via `assertInertia`, the missing-recipient safe-skip, and `notifications.stream` without auth → redirect to login. `NotificationServiceTest` (3 cases) covers row creation when the user exists, safe skip when absent, and persistence despite a thrown Redis publish. Not tested (and correctly deferred to the manual smoke test per design §12.3): the live SSE stream delivering a real `data:` message, re-subscribability after ≥3 idle heartbeats, nginx buffering/timeout behavior, and the phpredis `OPT_READ_TIMEOUT` semantics — none of which are reachable in a single-process headless suite.

### Verification evidence

`verification.md` records `pint --test` clean on 14 changed files, `php artisan test` at 214 passed / 4 failed with the 4 failures confirmed pre-existing (SIKEP external DB `Access denied for user 'ws_sikep'`, reproduced by stashing the change), `--filter=Notification` at 14 passed, and `npm run build` succeeding. The 4 failures are unrelated to this change by the author's stash experiment and by the nature of the failure (external SIKEP connection, not touched by this diff). Per instructions these suites were not re-run; the evidence is present and specific, so no spot-check was warranted.

</details>

<details>
<summary>File map</summary>

- `app/Services/NotificationService.php` — new single notification writer: resolve by `sikep_user_id`, persist row, best-effort Redis publish.
- `app/Services/TaskService.php` — inject notifier; post-commit `task.submitted` on create, gated re-notify on update.
- `app/Services/ReviewService.php` — inject notifier; post-commit `task.approved`/`task.revisi`/`task.rejected`.
- `app/Services/AssignmentService.php` — inject notifier; post-commit `task.assigned` to the Katimker argument.
- `app/Http/Controllers/NotificationController.php` — REST index/markRead/markAllRead with ownership 403.
- `app/Http/Controllers/NotificationStreamController.php` — SSE loop: 1s read-timeout, wall-clock heartbeat, per-exception connection rebuild, single close.
- `app/Http/Middleware/HandleInertiaRequests.php` — lazy `notifications` shared prop (15 items + `has_unread`).
- `app/Models/DatabaseNotification.php` — custom model with microsecond `$dateFormat`.
- `app/Models/User.php` — `notifications()` override to the custom model.
- `config/database.php` — dedicated `notifications` Redis connection, empty prefix.
- `database/migrations/2026_10_14_100001_create_notifications_table.php` — standard schema, microsecond timestamps, sorts after last migration.
- `routes/web.php` — 4 routes inside the `['auth','active-role','two-factor']` group.
- `resources/js/composables/useNotifications.js` — module-singleton EventSource + polling fallback + idle-update brancher.
- `resources/js/Layouts/AppLayout.vue` — `init()` on mount, no teardown on unmount.
- `resources/js/layout/AppTopbar.vue` — red-dot bell + Popover history.
- `docker/nginx/default.conf` — `location = /sse/notifications` with buffering/gzip off, long timeout.
- `docker/php/php-fpm.conf` — `pm.max_children = 128`, `request_terminate_timeout = 3700`.
- `.env.example` — `REDIS_NOTIFY_DB`, `SSE_HEARTBEAT_SECONDS`, prod/dev notes.
- `tests/Feature/NotificationTest.php`, `tests/Unit/NotificationServiceTest.php` — 14 cases total.

Full diff: `git -C f:\LARAVEL\remunisasi_v2\.worktrees\notif-realtime-sse diff main`

</details>
