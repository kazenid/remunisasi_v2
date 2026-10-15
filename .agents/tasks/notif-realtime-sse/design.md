# Sistem Notifikasi Realtime (SSE + Redis Pub/Sub) — Requirements & Design

Status: Revisi pass ke-4 (menanggapi `design-review.json` terbaru, verdict CHANGES_REQUESTED — 0 HIGH, 2 MEDIUM, 4 NIT; semua ditutup di pass ini). Pass ke-3 sebelumnya menyelesaikan 1 HIGH / 2 MEDIUM / 2 NIT; pass ke-2 menyelesaikan 3 HIGH / 7 MEDIUM / 3 NIT. Lihat §14 "Tanggapan atas Temuan Review" untuk pemetaan temuan→perubahan terbaru.
Codebase referensi: worktree `f:\LARAVEL\remunisasi_v2\.worktrees\notif-realtime-sse` (diverifikasi ulang membaca file aktual pada pass ini).

---

## 1. Requirements

### 1.1 Summary

Aplikasi TUKIMAN saat ini tidak informatif terhadap perubahan status/approval: pengguna harus me-refresh halaman untuk melihat perubahan data dan status penilaian. Fitur ini menambahkan:

1. **Badge titik merah (tanpa angka)** pada ikon lonceng di topbar saat ada notifikasi belum dibaca.
2. **Ikon lonceng + panel dropdown riwayat notifikasi**, tiap item bisa diklik → deep-link ke halaman tugas/penilaian terkait, dan klik menandai item terbaca.
3. **Data realtime**: ketika pengguna lain melakukan perubahan (submit tugas, approve/revisi/reject, penugasan baru), halaman yang sedang terbuka ("idle") memperbarui datanya tanpa refresh manual.

Transport yang disetujui bersama pengguna (JANGAN dilitigasi ulang): **Server-Sent Events (SSE)** lewat route Laravel di php-fpm (bukan websockets/Reverb), yang subscribe ke channel **Redis pub/sub per-user**. Setiap domain event menerbitkan sinyal ringan ke channel Redis user target; koneksi SSE mendorongnya ke browser. **Tidak ada polling DB di dalam loop SSE.** Fallback: polling ringan ~60 detik hanya saat SSE gagal/tertutup.

Model dua lapis per event:
- **(a) Persisten**: baris di tabel `notifications` Laravel (riwayat lonceng).
- **(b) Sinyal ringan**: publish best-effort ke channel Redis `notif.user.{sikep_user_id}` (memicu SSE). DB adalah source of truth; Redis bersifat best-effort dan kegagalannya TIDAK boleh menggagalkan request HTTP maupun test.

### 1.2 Functional Requirements

- **FR-1** Setiap domain event bernilai-notifikasi membuat satu baris `notifications` untuk User penerima yang benar (diresolusi via `sikep_user_id`) DAN menerbitkan sinyal ke channel Redis penerima.
- **FR-2** Event yang memicu notifikasi:
  - Katimker submit tugas baru (`TaskService::create`) → notifikasi ke Kabid penilai (`kabid_sikep_user_id`).
  - Katimker memperbarui tugas sehingga kembali ke penilaian (`TaskService::update`, saat status lama ≠ `diajukan`) → notifikasi ke Kabid.
  - Kabid approve/revisi/reject (`ReviewService::approve/revisi/reject`) → notifikasi ke Katimker pemilik (`katimker_sikep_user_id`).
  - Kabid/Admin memberi penugasan tambahan (`AssignmentService::createForKatimker`) → notifikasi ke Katimker.
- **FR-3** Topbar menampilkan ikon lonceng. Titik merah (tanpa angka) muncul bila ada notifikasi `read_at = null` untuk user auth.
- **FR-4** Klik lonceng membuka panel riwayat (overlay) berisi N notifikasi terbaru (judul/pesan Indonesia pendek, waktu relatif, indikator belum-dibaca).
- **FR-5** Klik satu item: menandai terbaca (POST) lalu menavigasi ke `url` deep-link item.
- **FR-6** Tombol "Tandai semua terbaca" mengosongkan badge.
- **FR-7** Realtime idle-update: saat sinyal SSE diterima dan halaman terbuka relevan dengan event, panggil `router.reload({ only: [...] })` Inertia untuk menyegarkan prop terkait tanpa refresh penuh.
- **FR-8** SSE auto-reconnect (native EventSource). Bila SSE gagal/tertutup, aktifkan polling fallback ~60 detik ke endpoint daftar notifikasi agar titik merah tetap muncul. Polling berhenti saat `document.hidden`; SSE di-resume saat memungkinkan.
- **FR-9** Prop awal `notifications` (≈15 terbaru) + `has_unread` di-inject lewat `HandleInertiaRequests::share()` agar lonceng langsung ter-seed tanpa menunggu SSE.

### 1.3 Non-Functional Requirements

- **NFR-1 (Ketahanan)** Kegagalan Redis (publish/subscribe) tidak pernah menggagalkan request HTTP, service, atau test. Semua interaksi Redis dibungkus try/catch + log.
- **NFR-2 (Isolasi koneksi)** SSE memakai koneksi/subscriber Redis **terdedikasi** (`notifications`), terpisah dari cache/session agar blocking subscribe tidak mengganggu koneksi lain.
- **NFR-3 (Cross-platform)** Kode jalan di Windows (dev lokal) dan Linux (Docker prod). Hindari API khusus OS. Catatan dev-server (Finding 3): `php artisan serve` single-proses; koneksi SSE panjang akan memblokir seluruh app dev. Mitigasi & verifikasi di §10.4 — fitur tetap berfungsi via fallback polling walau SSE tidak pernah tersambung di dev single-proses.
- **NFR-4 (Keamanan)** Endpoint SSE & REST berada di dalam grup middleware `['auth','active-role','two-factor']`. Channel Redis per-user berbasis `sikep_user_id` user terautentikasi; user tidak bisa menyadap channel user lain.
- **NFR-5 (Resource)** Loop SSE tidak boleh memboroskan memori/CPU: heartbeat ~20 dtk, keluar bersih saat `connection_aborted()`, tanpa busy-loop ketat.

### 1.4 Acceptance Criteria

1. Saat Kabid approve tugas, tepat satu baris `notifications` dibuat untuk User dengan `sikep_user_id = katimker_sikep_user_id`, bertipe `task.approved`, dengan `data.url` = `route('katimker.tugas.show', tugas)`.
2. Saat Kabid revisi/reject, satu baris `notifications` untuk Katimker pemilik bertipe `task.revisi`/`task.rejected` dengan pesan Indonesia yang sesuai.
3. Saat Katimker submit tugas baru, satu baris `notifications` untuk User dengan `sikep_user_id = kabid_sikep_user_id` bertipe `task.submitted`, `data.url` = `route('kabid.penilaian.show', tugas)`.
4. Saat Katimker update tugas berstatus `revisi`/`menunggu_bukti` (kembali ke `diajukan`), notifikasi submit dikirim ke Kabid; update tugas yang sudah `diajukan` TIDAK mengirim notifikasi.
5. Saat Kabid/Admin memberi penugasan tambahan, satu baris `notifications` untuk Katimker bertipe `task.assigned`, pesan "Penugasan baru menunggu bukti".
6. Endpoint mark-one-read mengubah `read_at` menjadi non-null untuk notifikasi milik user auth saja; notifikasi milik user lain menghasilkan 403/404.
7. Endpoint mark-all-read mengubah `read_at` seluruh notifikasi belum-dibaca milik user auth.
8. Shared prop `has_unread` bernilai `true` bila ada notifikasi `read_at = null`, dan `false` setelah semua ditandai terbaca.
9. Membuat notifikasi tetap berhasil (baris DB ada, request tidak error) walaupun Redis tidak tersedia (publish gagal → tercatat di log, tidak dilempar).
10. Endpoint SSE mengembalikan `Content-Type: text/event-stream`, dan hanya dapat diakses user terautentikasi (redirect/401 bila guest).
11. `php artisan test` lulus; `npm run build` sukses; `./vendor/bin/pint` tanpa pelanggaran pada file baru.
12. (Verifikasi manual, image php Docker) SSE mengantar pesan NYATA (bukan hanya heartbeat) DAN tetap mengantar pesan setelah ≥60 dtk idle sebelumnya (≥3 heartbeat `: ping` terkirim di antaranya) — membuktikan `subscribe()` tetap re-subscribable setelah berkali-kali read-timeout (lihat §6.2 rebuild koneksi & §12.3).

### 1.5 Out of Scope

- Websocket/Laravel Reverb/Echo (sudah ditolak; SSE final).
- Notifikasi email/push/mobile.
- Preferensi per-user untuk mematikan jenis notifikasi tertentu.
- Angka hitungan pada badge (eksplisit hanya titik merah).
- Notifikasi untuk peran Admin (tidak ada event yang di-scope ke Admin pada iterasi ini).

### 1.6 Assumptions

- Driver broadcast tetap `log`; fitur ini TIDAK memakai sistem broadcasting Laravel — publish Redis dilakukan langsung lewat facade `Redis`.
- Di produksi Redis & MySQL berada di host (bukan container app); host dijangkau via env (mis. `host.docker.internal` atau IP host). Catatan: `docker-compose.yml` saat ini menyertakan container `postgres` & `redis` dan `APP_ENV=production` — ini TIDAK sejalan dengan arsitektur yang disetujui (MySQL + Redis di host). Design ini mengikuti arsitektur yang disetujui; penyelarasan compose produksi dicatat sebagai item di §9 (bukan diputuskan ulang di sini).
- Setiap `sikep_user_id` memetakan ke tepat satu baris `users` lokal (kolom `sikep_user_id` unik — dikonfirmasi di migrasi users).
- **Tidak ada morph map yang di-enforce (NORMATIF, Finding R4-4 / review ke-4 NIT #4).** Diverifikasi: tidak ada `Relation::enforceMorphMap`/`Relation::morphMap` di seluruh `app/`. Karena itu kolom `notifiable_type` menyimpan FQCN literal `App\Models\User`, dan semua perbandingan `where('notifiable_type', User::class)` (di `has_unread` §3.3, otorisasi mark-read §7, serta assertion test `notifiable_type => User::class`) cocok. Asumsi ini load-bearing: JIKA kelak morph map diperkenalkan, SETIAP perbandingan `notifiable_type` dan seluruh assertion test WAJIB diubah ke alias morph (mis. `'user'`), bukan FQCN.

---

## 2. Technology Stack (terkunci setelah disetujui)

- Backend: Laravel (php-fpm 8.4), Eloquent, trait `Notifiable` + tabel `notifications` bawaan Laravel.
- Realtime transport: SSE via route Laravel (streamed response), Redis pub/sub (`phpredis`) koneksi terdedikasi.
- Frontend: Vue 3 + Inertia + PrimeVue (Sakai/Aura) + Tailwind; native `EventSource`; `@inertiajs/vue3` `router.reload`.
- Routing helper: Ziggy (`route()` server-side & client-side) — sudah terpasang.
- DB: MySQL (prod/dev host), SQLite in-memory (test).
- Build/test: `php artisan test` (PHPUnit 12), `npm run build` (Vite), `./vendor/bin/pint`.

---

## 3. Data Model

### 3.1 Migrasi `notifications`

Tabel `notifications` bawaan Laravel belum ada (dikonfirmasi: tidak ada migrasinya di `database/migrations`). Tambahkan migrasi baru dengan **timestamp yang mengurut SETELAH migrasi terakhir yang ada** (`2026_10_09_100002_create_incentive_results_table.php`); pakai tanggal hari implementasi (format `YYYY_MM_DD_HHMMSS_create_notifications_table.php`). Tabel `notifications` TIDAK memiliki FK ke `users`, jadi urutan antar-migrasi tidak load-bearing — satu-satunya syarat adalah namanya mengurut setelah migrasi terakhir. (Koreksi review ke-3: premis "jangan tanggal masa depan" pada draf lama keliru — tanggal sistem saat ini `2026_10_14` dan migrasi existing bertanggal `2026_10_06`…`2026_10_09`, sehingga `2026_10_xx` adalah tanggal berjalan, bukan masa depan.) Skema mengikuti standar Laravel (UUID PK, polymorphic notifiable) dengan satu penyesuaian: **presisi mikrodetik pada timestamp** agar urutan deterministik:

```php
Schema::create('notifications', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('type');
    $table->morphs('notifiable');        // notifiable_type + notifiable_id (index)
    $table->text('data');                // JSON payload
    $table->timestamp('read_at', 6)->nullable();   // presisi mikrodetik
    $table->timestamp('created_at', 6)->nullable();
    $table->timestamp('updated_at', 6)->nullable();
});
```

Keputusan presisi (Finding 8): PK adalah UUID acak (bukan auto-increment), jadi `->latest()` yang hanya mengurutkan `created_at` pada presisi detik memberi tie-break tak-deterministik untuk dua notifikasi di detik yang sama (umum saat satu aksi menotifikasi, atau di test).

Diverifikasi pada pass ini terhadap framework terpasang (`laravel/framework ^13.8`): (a) properti statis `Model::$useDateTimeWithMicroseconds` **TIDAK ADA** di framework ini (grep tanpa hasil) — rekomendasi flag global pada draf sebelumnya SALAH dan dihapus; (b) `Illuminate\Notifications\DatabaseNotification` memakai `$dateFormat` default (`Y-m-d H:i:s`, tanpa mikrodetik), `casts` hanya `data`→array dan `read_at`→datetime, dan tidak men-set presisi mikrodetik. Jadi menulis lewat relasi framework apa adanya akan menyimpan `created_at` presisi detik.

Solusi normatif: **model notifikasi kustom** `App\Models\DatabaseNotification extends \Illuminate\Notifications\DatabaseNotification` dengan `protected $dateFormat = 'Y-m-d H:i:s.u';` (tabel sama `notifications`). Arahkan trait `Notifiable` memakai model ini dengan meng-override di model `User`:

```php
// app/Models/User.php
public function notifications()
{
    return $this->morphMany(\App\Models\DatabaseNotification::class, 'notifiable')->latest();
}
```

Dengan `$dateFormat` mikrodetik, INSERT menulis `created_at` presisi mikrodetik (kolom `timestamp(col, 6)` di migrasi menampungnya), sehingga `->latest()` (= `orderByDesc('created_at')`) deterministik untuk kasus praktis.

**Invarian write-path (NORMATIF, Finding R4-3 / review ke-4 NIT #3).** Model kustom `App\Models\DatabaseNotification` WAJIB mempertahankan Eloquent timestamps AKTIF (`$timestamps = true`, nilai default — JANGAN set `false`, JANGAN tambahkan `const UPDATED_AT = null`/`const CREATED_AT = null`, JANGAN override penanganan `created_at`). `created_at` ditulis SEMATA-MATA oleh Eloquent melalui `$dateFormat = 'Y-m-d H:i:s.u'`; `notify()` (§5) sengaja TIDAK men-set `created_at` secara eksplisit dan mengandalkan mekanisme timestamp ini untuk presisi mikrodetik. Menonaktifkan `$timestamps` atau meng-override `created_at` akan diam-diam menjatuhkan komponen mikrodetik dan mengembalikan non-determinisme urutan yang §3.1 ini justru dibangun untuk mencegah. Karena `$user->notifications()`, `unreadNotifications()`, dan route-model-binding kini mengacu model kustom (atau framework—keduanya membaca string mikrodetik tersimpan yang mengurut benar), ordering konsisten di panel, index, dan shared prop. Route-binding `{notification}` boleh tetap ke model kustom; set `->name('notifications...')` tidak terpengaruh. Alternatif lebih ringan jika implementor menolak model kustom: tambah kolom urут monotonik `seq` `bigIncrement` dan `orderByDesc('seq')` — namun model kustom + `$dateFormat` lebih kecil jejaknya dan dipilih sebagai normatif.

`notifiable_id` menyimpan `users.id` **lokal** (PK), karena trait `Notifiable` memakai primary key model User. Resolusi dari `sikep_user_id` dilakukan sebelum menulis (lihat §4). `down()` → `dropIfExists('notifications')`.

Catatan kompatibilitas SQLite (test): `uuid` + `morphs` + `text` didukung driver sqlite in-memory. Presisi mikrodetik `timestamp(col, 6)` pada SQLite disimpan sebagai string; model kustom dengan `$dateFormat = 'Y-m-d H:i:s.u'` menulis komponen mikrodetik, dan SQLite mengurutkan string tanggal ini secara leksikal benar → ordering tetap deterministik di test → aman untuk `RefreshDatabase`. Jika dua INSERT tetap jatuh di mikrodetik yang persis sama (sangat jarang), test ordering sebaiknya menegaskan keanggotaan himpunan, bukan urutan antar-dua-item sedetik, untuk menghindari flakiness (lihat §12).

### 3.2 Bentuk payload `data`

JSON disimpan di kolom `data`:

```json
{
  "type": "task.approved",
  "message": "Tugas Anda telah disetujui.",
  "url": "/katimker/tugas/123",
  "task_id": 123
}
```

- `type` (string, enum aplikasi): `task.submitted`, `task.approved`, `task.revisi`, `task.rejected`, `task.assigned`.
- `message` (string Indonesia, pendek, mengikuti nada flash yang ada).
- `url` (string) deep-link **RELATIF** hasil `route(name, param, absolute: false)` → `/katimker/tugas/123`. **Wajib relatif** (parameter ketiga `false`): URL ini dikonsumsi oleh `router.visit(url)` Inertia di klien. `route()` default mengembalikan URL absolut (`https://host/...`); di produksi (app di Docker di belakang proxy, `APP_URL` bisa berbeda dari origin browser mis. `host.docker.internal`) URL absolut dapat salah host dan memicu full reload / navigasi gagal. URL relatif menghindari ini (lihat Finding 7). Nilai yang sama disimpan di DB `data.url`, dikirim di sinyal Redis, dan dipetakan di shared prop/REST — ketiganya identik.
- **Aturan sumber-tunggal `url` (NORMATIF, Finding NIT review ke-3).** URL dibangun TEPAT SEKALI sebagai argumen `$url` ke `NotificationService::notify(...)` lewat `route(name, $task, false)` (§4/§5). Argumen tunggal itu adalah SATU-SATUNYA sumber: ia ditulis ke `data.url` pada baris DB dan dipakai ulang untuk sinyal Redis dari variabel yang sama, sehingga identik secara konstruksi. Semua pembaca hilir — mapper shared-prop (§8), mapper REST `index` (§7), dan payload sinyal (§5) — **WAJIB membaca kembali `data.url`** dan **TIDAK BOLEH memanggil ulang `route()`**. Larangan ini mencegah ada jalur yang menghasilkan URL absolut (mis. `route()` tanpa `false`) sehingga regresi "URL absolut di satu jalur" tidak mungkin muncul.
- `task_id` (int) untuk korelasi idle-update di frontend.

`id`/`created_at`/`read_at` berasal dari kolom tabel, bukan dari `data`. API & shared prop memetakannya eksplisit (§5, §6).

### 3.3 `has_unread` & `read_at`

- `read_at` adalah kolom tabel; null = belum dibaca.
- `has_unread` dihitung sebagai `DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->whereNull('read_at')->exists()` (query ringan `EXISTS`).
- Urutan panel/daftar: `->orderByDesc('created_at')` memakai presisi mikrodetik (§3.1) sehingga deterministik; `->latest()` setara dan dipakai di §7/§8.

---

## 4. Recipient Resolution & Teks Pesan

Identitas memakai `sikep_user_id`, bukan `users.id`. Helper notifikasi menerima `sikep_user_id` target lalu meresolusi User lokal: `User::where('sikep_user_id', $sikepUserId)->first()`. Bila User tidak ditemukan (mis. belum pernah login/sync), **lewati dengan aman** (log info, tidak error) — DB row tetap tidak dibuat untuk hantu, request utama tidak terpengaruh.

Pemetaan per event (sumber data = argumen service / kolom `TaskReport`):

| Event (service method) | Penerima (sikep_user_id) | `type` | `message` (ID) | `url` (route name) |
|---|---|---|---|---|
| `TaskService::create()` | `$task->kabid_sikep_user_id` | `task.submitted` | "Ada tugas baru untuk dinilai." | `route('kabid.penilaian.show', $task, false)` |
| `TaskService::update()` (hanya bila `$statusLama !== DIAJUKAN`) | `$task->kabid_sikep_user_id` | `task.submitted` | "Tugas diperbarui dan diajukan kembali untuk dinilai." | `route('kabid.penilaian.show', $task, false)` |
| `ReviewService::approve()` | `$task->katimker_sikep_user_id` | `task.approved` | "Tugas Anda telah disetujui." | `route('katimker.tugas.show', $task, false)` |
| `ReviewService::revisi()` | `$task->katimker_sikep_user_id` | `task.revisi` | "Tugas Anda diminta untuk direvisi." | `route('katimker.tugas.show', $task, false)` |
| `ReviewService::reject()` | `$task->katimker_sikep_user_id` | `task.rejected` | "Tugas Anda ditolak." | `route('katimker.tugas.show', $task, false)` |
| `AssignmentService::createForKatimker()` | `$katimkerSikepUserId` (argumen) | `task.assigned` | "Penugasan baru menunggu bukti." | `route('katimker.tugas.show', $task, false)` |

Catatan `ubahJenis()`: tidak memicu notifikasi tersendiri. Saat dipanggil dari `approve()` dengan `jenisBaru`, notifikasi `task.approved` tunggal sudah mencakupnya. Pemanggilan `ubahJenis()` langsung (route `kabid.penilaian.jenis`) tidak menghasilkan notifikasi pada iterasi ini (di luar lingkup FR-2).

URL dibangun server-side dengan `route('katimker.tugas.show', $task, false)` / `route('kabid.penilaian.show', $task, false)` — parameter ketiga `false` memaksa URL **relatif** (Finding 7). Karena route membutuhkan param `tugas` numerik, URL dibentuk setelah `$task` punya `id` dan setelah commit (lihat §5/§5.1 — notify dipanggil pasca-commit dengan `$task` yang sudah di-return/refresh).

---

## 5. Notification Service / Helper

Buat `app/Services/NotificationService.php`:

```php
final class NotificationService
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Persist baris notifikasi untuk User (resolusi via sikep_user_id) lalu
     * publish sinyal best-effort ke channel Redis user.
     */
    public function notify(int $sikepUserId, string $type, string $message, string $url, array $extra = []): void
    {
        $user = User::query()->where('sikep_user_id', $sikepUserId)->first();
        if ($user === null) {
            Log::info('Notifikasi dilewati: user lokal tidak ditemukan', ['sikep_user_id' => $sikepUserId, 'type' => $type]);
            return;
        }

        $payload = array_merge(['type' => $type, 'message' => $message, 'url' => $url], $extra);

        // (a) SUMBER KEBENARAN: baris persisten. Memakai DatabaseNotification langsung
        //     agar tidak perlu kelas Notification + channel.
        $notification = $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => $payload,
        ]);

        // (b) BEST-EFFORT: sinyal realtime. Kegagalan Redis TIDAK boleh naik.
        $this->publish($sikepUserId, [
            'id' => $notification->id,
            'type' => $type,
            'message' => $message,
            'url' => $url,
            'task_id' => $extra['task_id'] ?? null,
            'created_at' => $notification->created_at->toIso8601String(),
        ]);
    }

    private function publish(int $sikepUserId, array $signal): void
    {
        try {
            Redis::connection('notifications')
                ->publish("notif.user.{$sikepUserId}", json_encode($signal, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::warning('Publish notifikasi Redis gagal (diabaikan)', [
                'sikep_user_id' => $sikepUserId, 'error' => $e->getMessage(),
            ]);
        }
    }
}
```

Catatan desain:
- `$user->notifications()` di-override di model `User` (§3.1) menjadi relasi `morphMany` ke `App\Models\DatabaseNotification` (subclass dengan `$dateFormat` mikrodetik). `data` tetap di-cast ke/dari JSON (`casts` diwarisi), jadi menulis array langsung aman. Memakai relasi ini (bukan class Notification + `->notify()`) menghindari ketergabungan ke queue/channel dan tetap mengisi `notifiable_type`/`notifiable_id` dengan benar. `id` di-set eksplisit (`Str::uuid()`) karena model notifikasi tidak meng-generate UUID otomatis.
- `Redis::connection('notifications')` memakai koneksi terdedikasi baru (§10.3) sehingga `publish` tidak memakai koneksi cache/session. **Prefix channel (Finding 2):** koneksi `notifications` dikonfigurasi dengan `'options' => ['prefix' => '']` sehingga nama channel literal `notif.user.{id}` (tanpa prefix global `{app-slug}-database-`). Dengan begitu publisher di sini dan subscriber di SSE (§6.2) sama-sama memakai nama literal, dan verifikasi manual `redis-cli SUBSCRIBE notif.user.X` (§12.3) melihat channel yang sama.
- **Transaksi**: `notify()` dipanggil SETELAH `DB::transaction` di tiap service `commit` (bukan di dalamnya), agar baris notifikasi tidak ikut rollback jika transaksi inti gagal, dan agar `$task->id` sudah pasti ada. Lihat wiring di §5.1.

### 5.1 Wiring ke service

**Keputusan lapisan (Finding 10 — NORMATIF untuk keempat service).** Panggilan `notify()` berada di **lapisan service**, bukan controller. Alasan konkret yang terverifikasi: `ReviewController::assignStore()` memanggil `$assignment->createForKatimker(...)` **tanpa menangkap nilai balik** (lihat kode: tidak ada `$task = ...`), lalu langsung `redirect()`. Jadi tidak ada titik pasca-commit di controller untuk penugasan. Agar satu lapisan konsisten di semua event, keempat service memanggil `notify()` sendiri. Ini juga sejalan dengan §11 ("NotificationService satu-satunya penulis").

**Pola seragam (WAJIB sama di keempat service):** tangkap hasil transaksi ke variabel, panggil `notify()` pada variabel **pasca-commit** (model yang sudah di-return/refresh, bukan `$task` pra-transaksi yang bisa basi/detached), lalu `return`:

```php
$task = DB::transaction(fn () => /* ... */);
$this->notifier->notify($task-><recipient>, '<type>', '<message>', route('<name>', $task, false), ['task_id' => $task->id]);
return $task;
```

Inject `NotificationService` via constructor (mengikuti pola DI `AuditLogger` — service injectable tanpa dependensi khusus, auto-resolve container).

- **TaskService** (`__construct(AuditLogger $audit, NotificationService $notifier)`):
  - `create()`: restruktur `return $task;` di dalam closure menjadi menyimpan hasil ke variabel; pasca-commit `$this->notifier->notify($task->kabid_sikep_user_id, 'task.submitted', 'Ada tugas baru untuk dinilai.', route('kabid.penilaian.show', $task, false), ['task_id' => $task->id]);` lalu `return $task;`.
  - `update()`: simpan `$statusLama` sebelum transaksi (sudah ada di kode saat ini); pasca-commit, **hanya bila** `$statusLama !== TaskReport::STATUS_DIAJUKAN`, kirim `task.submitted` ke `$task->kabid_sikep_user_id` pesan "Tugas diperbarui dan diajukan kembali untuk dinilai.".
- **ReviewService** (`__construct(AuditLogger $audit, NotificationService $notifier)`):
  - `approve()`: method mengembalikan `TaskReport` (dikonfirmasi `: TaskReport`). Simpan hasil transaksi ke variabel, pasca-commit `notify($task->katimker_sikep_user_id, 'task.approved', 'Tugas Anda telah disetujui.', route('katimker.tugas.show', $task, false), ['task_id' => $task->id])`. Catatan `approve()` dapat memanggil `ubahJenis()` (savepoint nested) — tidak masalah: notify memakai hasil pasca-commit (refreshed), `route(...)` hanya butuh `$task->id` yang stabil.
  - `revisi()` / `reject()`: keduanya `return $this->kembalikan(...)`. Agar `kembalikan()` tetap generik, kirim notifikasi **di method publik `revisi()`/`reject()`** setelah menangkap hasilnya: `$task = $this->kembalikan(...); $this->notifier->notify($task->katimker_sikep_user_id, 'task.revisi'|'task.rejected', ..., route('katimker.tugas.show', $task, false), ['task_id' => $task->id]); return $task;`. `revisi` → `task.revisi` "Tugas Anda diminta untuk direvisi."; `reject` → `task.rejected` "Tugas Anda ditolak.". (`kembalikan()` sendiri sudah menjalankan transaksinya dan mengembalikan task, jadi ini sudah pasca-commit.)
- **AssignmentService** (`__construct(AuditLogger $audit, NotificationService $notifier)`):
  - **Finding 9 — eksplisit.** Controller `assignStore` **method-inject** `AssignmentService` dan tidak diubah (container tetap me-resolve `NotificationService` ke constructor service; method-injection controller tetap bekerja — tidak ada perubahan signature controller). `createForKatimker()` saat ini `return DB::transaction(fn () => ... return $task;)`. Restruktur menjadi: `$task = DB::transaction(fn () => ...); $this->notifier->notify($katimkerSikepUserId, 'task.assigned', 'Penugasan baru menunggu bukti.', route('katimker.tugas.show', $task, false), ['task_id' => $task->id]); return $task;`. Penerima adalah argumen `$katimkerSikepUserId` (bukan kolom), konsisten dengan verifikasi review.

Semua service mengembalikan `$task` yang sama seperti sebelumnya; satu-satunya perubahan struktural adalah memindah `return` ke variabel agar ada titik pasca-commit. Test §12.1 #5 menggerakkan HTTP route `kabid.penugasan.store` → `ReviewController::assignStore` → service, sehingga notify ikut terpicu lewat jalur nyata.

---

## 6. SSE Controller + Route

### 6.1 Route

Tambahkan di `routes/web.php` di dalam grup `Route::middleware(['auth','active-role','two-factor'])` (sejajar dashboard/logout, sehingga mewarisi proteksi yang sama):

```php
Route::get('/sse/notifications', [NotificationStreamController::class, 'stream'])->name('notifications.stream');
Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
```

`{notification}` memakai route-model-binding ke `App\Models\DatabaseNotification` (subclass dari `Illuminate\Notifications\DatabaseNotification`, §3.1; mewarisi PK UUID `incrementing = false`, `keyType = string`; binding implisit resolve by `id`, 404 untuk UUID tak dikenal — diuji di §12.1 #7). Type-hint controller `markRead(Request, \App\Models\DatabaseNotification $notification)`.

**Finding 6 (admin 2FA mid-enrollment):** keempat route + SSE berada di grup `['auth','active-role','two-factor']`. Terverifikasi di `EnsureTwoFactor`: admin yang BELUM konfirmasi 2FA di-redirect 302 ke `two-factor.setup` untuk setiap route selain setup/confirm/logout. Konsekuensi: untuk admin tsb, `GET /sse/notifications` → 302 (EventSource memperlakukannya sebagai error → `onerror` → polling) dan `GET /notifications` → 302→HTML (di-handle guard content-type di `pollOnce`, lihat §9.1). Ini dapat diterima: notifikasi Admin memang di luar lingkup (§1.5), jadi admin mid-enrollment cukup tidak punya data lonceng; tidak ada crash klien.

### 6.2 `NotificationStreamController::stream()`

Mengembalikan `Symfony\Component\HttpFoundation\StreamedResponse`:

Header:
- `Content-Type: text/event-stream`
- `Cache-Control: no-cache`
- `X-Accel-Buffering: no` (menonaktifkan buffering nginx)
- `Connection: keep-alive`

**Keputusan loop & heartbeat (Finding 1 HIGH + Finding 3 MED — SATU pola normatif, deterministik).** Review ke-2 menolak pola sebelumnya karena menggantungkan heartbeat & deteksi abort pada perilaku `OPT_READ_TIMEOUT` yang TAK TERVERIFIKASI (apakah `subscribe()` benar-benar melempar `\RedisException` saat channel idle). Fallback lama ("andalkan echo/flush berikutnya") tidak sahih: pada channel idle tidak ada tulisan apa pun, jadi tidak ada SIGPIPE untuk mendeteksi abort, dan `: ping` tidak pernah terkirim (NFR-5 gagal diam-diam) — worker bocor sampai `request_terminate_timeout`. Pass ini menghapus ketergantungan itu.

**Pola normatif: read-timeout DETERMINISTIK 1 detik + akumulator heartbeat wall-clock.** Loop luar `while (! connection_aborted())` memanggil `subscribe()` dengan `OPT_READ_TIMEOUT = 1` (dtk). Setiap iterasi `subscribe()` kembali/melempar dalam ≤1 dtk, sehingga loop luar SELALU berputar tiap ~1 dtk untuk (a) mengecek `connection_aborted()` dan (b) mengirim `: ping` bila `time() - $last >= $hb` (default `$hb = 20` dtk, dari `SSE_HEARTBEAT_SECONDS`). Dengan begitu heartbeat dan deteksi abort TIDAK LAGI bergantung pada apakah phpredis melempar — jika `subscribe()` kembali tanpa melempar pada timeout, loop tetap berputar; jika melempar `\RedisException`, kita tangkap sebagai tick idle yang diharapkan dan lanjut. Kedua kemungkinan perilaku phpredis diterima tanpa mengubah korektness.

**Konsistensi lifecycle koneksi (Finding 3).** Deteksi abort HANYA di loop luar; callback TIDAK PERNAH memanggil `$redis->close()` mid-stream. Jadi tidak ada dua jalur unblock dengan state koneksi berbeda: koneksi terdedikasi ditutup SEKALI di `finally` saat loop berakhir (abort/exception). Satu lifecycle, satu titik `close()`.

**Re-subscribability setelah read-timeout (Finding R4-1 / review ke-4 MEDIUM #1 — NORMATIF).** Masalah yang ditemukan: pada banyak build phpredis, setelah `subscribe()` melempar `\RedisException` karena `OPT_READ_TIMEOUT`, socket client tertinggal di state subscriber/rusak yang TIDAK kembali bersih ke state command-ready. Me-`subscribe()` ulang pada handle YANG SAMA bisa gagal terus-menerus → loop menjadi error-spin yang hanya mengirim `: ping` dan TIDAK PERNAH mengantar pesan nyata (SSE tampak sehat tapi mati diam-diam). Draf sebelumnya mengasumsikan handle dapat dipakai ulang apa adanya — asumsi itu DIHAPUS.

Keputusan normatif: **DISCARD + REBUILD koneksi terdedikasi setiap kali `subscribe()` melempar `\RedisException`** (jalur read-timeout/idle), sebelum `subscribe()` berikutnya. Konkretnya, pada `catch (\RedisException)`: `Redis::purge('notifications');` lalu `$connection = Redis::connection('notifications');` lalu `$client = $connection->client();` dan terapkan ulang `$client->setOption(\Redis::OPT_READ_TIMEOUT, 1);`. `Redis::purge()` membuang instance koneksi yang ter-cache di RedisManager sehingga `connection()` berikutnya membuat socket baru yang bersih (bukan handle rusak yang sama). Ini menerima biaya reconnect yang murah sekali per idle-tick (~tiap `read_timeout` detik saat tak ada pesan) sebagai ganti jaminan bahwa `subscribe()` berikutnya selalu dijalankan pada socket command-ready. Alternatif (b) — loop baca non-blocking eksplisit di bawah kendali desain — ditolak sebagai default karena menambah kompleksitas protokol pub/sub manual; rebuild-per-tick lebih sederhana dan deterministik. Catatan beban: karena `OPT_READ_TIMEOUT` di-set 1 dtk tetapi heartbeat `$hb` default 20 dtk, pada koneksi idle terjadi ~1 rebuild per detik; jika biaya reconnect per detik dinilai terlalu mahal pada deployment tertentu, implementor BOLEH menaikkan `OPT_READ_TIMEOUT` mendekati `$hb` (mis. 5 dtk) selama tetap < `$hb` agar cek `connection_aborted()` dan heartbeat masih berjalan tepat waktu — rebuild lalu terjadi ~sekali per read-timeout, bukan per detik. Rebuild WAJIB tetap ada di jalur `\RedisException` berapa pun nilai read-timeout-nya.

Catatan beban: read-timeout 1 dtk berarti loop berputar ~1×/dtk pada koneksi idle. Ini bukan busy-loop ketat — tiap iterasi memblokir sampai 1 dtk di `subscribe()` (menunggu I/O, bukan spin CPU). Biaya CPU dapat diabaikan; latensi pengiriman pesan tidak terpengaruh karena pesan nyata membangunkan `subscribe()` seketika. Nilai read-timeout 1 dtk bersifat tetap (konstanta loop) dan terpisah dari `$hb` heartbeat; `$hb` bersumber tunggal dari `config('database.redis.notifications.read_timeout')` (= `SSE_HEARTBEAT_SECONDS`). JANGAN menyamakan read-timeout subscribe (1 dtk) dengan interval heartbeat (20 dtk) — keduanya peran berbeda.

Satu strategi flushing (Finding 12 lama): akhiri semua buffer SEKALI di awal, lalu helper `sse()` echo+flush. Tidak ada `ignore_user_abort(false)` redundan (itu nilai default).

```php
public function stream(Request $request): StreamedResponse
{
    $sikepUserId = (int) $request->user()->sikep_user_id;

    $response = new StreamedResponse(function () use ($sikepUserId) {
        @set_time_limit(0);                       // nonaktifkan batas eksekusi stream
        while (ob_get_level() > 0) { @ob_end_flush(); }   // akhiri semua buffer SEKALI

        $this->sse(': connected');                // komentar awal → buka stream

        $channel = "notif.user.{$sikepUserId}";
        $hb = (int) config('database.redis.notifications.read_timeout', 20); // interval heartbeat (dtk)
        $last = time();

        // Koneksi terdedikasi (§10.3). Raw phpredis client untuk set read-timeout.
        // Helper lokal: (re)bangun koneksi bersih + set OPT_READ_TIMEOUT=1 dtk.
        $connect = function () {
            Redis::purge('notifications');                 // buang instance ter-cache (socket lama)
            $connection = Redis::connection('notifications');
            $client = $connection->client();
            $client->setOption(\Redis::OPT_READ_TIMEOUT, 1); // DETERMINISTIK: tiap subscribe ≤1 dtk
            return [$connection, $client];
        };
        [$connection, $client] = $connect();

        try {
            while (! connection_aborted()) {
                try {
                    // Blok maksimal ~1 dtk: kembali/melempar cepat agar loop luar berputar.
                    $connection->subscribe([$channel], function ($redis, $chan, $message) {
                        $this->sse('data: '.$message);   // echo + flush langsung
                    });
                } catch (\RedisException $e) {
                    // Tick idle 1 dtk ATAU transien. Socket subscriber bisa tertinggal rusak
                    // (Finding R4-1) → DISCARD + REBUILD koneksi sebelum subscribe() berikutnya,
                    // supaya subscribe() berikutnya SELALU di socket command-ready.
                    [$connection, $client] = $connect();
                } catch (\Throwable $e) {
                    Log::debug('SSE subscribe berhenti', ['error' => $e->getMessage()]);
                    break;
                }
                if (time() - $last >= $hb) {   // heartbeat wall-clock, independen dari perilaku timeout
                    $this->sse(': ping');
                    $last = time();
                }
            }
        } finally {
            try { $client->close(); } catch (\Throwable $e) { /* abaikan */ }  // SATU titik close
        }
    });

    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache');
    $response->headers->set('X-Accel-Buffering', 'no');
    $response->headers->set('Connection', 'keep-alive');

    return $response;
}

/** Satu titik echo+flush; tidak menumpuk string di memori. */
private function sse(string $line): void
{
    echo $line."\n\n";
    if (function_exists('ob_flush')) { @ob_flush(); }
    @flush();
}
```

Mengapa ini menutup Finding 1, 3, dan R4-1 sekaligus: (1) heartbeat dikendalikan jam dinding di loop luar, tidak lagi tergantung apakah `subscribe()` melempar — jika build phpredis TIDAK melempar pada idle, `subscribe()` tetap kembali dalam ≤1 dtk (atau, pada build yang justru memblokir melebihi read-timeout, lihat catatan di bawah), loop berputar, `: ping` tetap terkirim tepat waktu, dan `connection_aborted()` tetap dicek tiap ≤1 dtk sehingga worker tidak bocor; (2) tidak ada `close()` di dalam callback, jadi tidak ada dua jalur unblock dengan state koneksi berbeda, dan koneksi ditutup tepat sekali di `finally`; (3) pada jalur `\RedisException` (read-timeout/idle), koneksi di-DISCARD + REBUILD (`Redis::purge('notifications')` → `Redis::connection('notifications')` → set ulang `OPT_READ_TIMEOUT`) sehingga `subscribe()` berikutnya SELALU dijalankan pada socket command-ready — tidak mungkin error-spin yang hanya mengirim heartbeat tanpa pernah mengantar pesan. Tidak ada kelas `StopStreaming` (dihapus).

Catatan presisi atas perilaku phpredis (bukan lagi dependensi korektness, hanya penajaman): secara empiris sebagian besar build phpredis MELEMPAR `\RedisException "read error on connection"` saat `OPT_READ_TIMEOUT` tercapai pada `subscribe()` idle — itulah jalur `catch (\RedisException)` di atas, yang pada pass ini juga me-rebuild koneksi (Finding R4-1). Bila sebuah build justru me-return tanpa melempar, jalur tanpa-exception tetap jatuh ke cek heartbeat/abort yang sama (tanpa rebuild — tidak perlu, karena socket tidak melewati error). Satu-satunya perilaku yang akan merusak heartbeat adalah jika `subscribe()` MENGABAIKAN `OPT_READ_TIMEOUT` dan memblokir selamanya; implementor WAJIB memverifikasi pada image Docker php bahwa read-timeout 1 dtk benar-benar membatasi blok (uji: `redis-cli` diam, amati `: ping` muncul tiap ~20 dtk via `curl -N`, §12.3). Jika ternyata diabaikan, alternatif deterministik: ganti `subscribe()` dengan loop `$client->rawCommand`/non-blocking atau polling pub/sub buffer pendek — tetapi default yang dipilih (read-timeout 1 dtk + rebuild per `\RedisException`) sudah menjadikan heartbeat independen dari exception DAN menjamin re-subscribability, dan itu yang diminta review.

Guard anti-buffering tak-hingga: tiap pesan langsung di-echo+flush; tidak ada array/string yang tumbuh. Batas atas umur koneksi dijaga `request_terminate_timeout`/`fastcgi_read_timeout` (§10); EventSource browser reconnect otomatis setelah koneksi berakhir.

Guard "satu subscriber per request": koneksi `notifications` di-resolve di dalam closure stream (bukan di konstruktor controller) agar tidak dipakai bersama request lain.

### 6.3 Edge cases SSE

- Klien menutup tab → `connection_aborted()` true → loop keluar, phpredis unsubscribe saat skrip berakhir.
- Redis down saat connect → exception ditangkap; stream mengirim komentar awal lalu berakhir; EventSource reconnect; sementara itu frontend fallback polling menyediakan titik merah (§7).
- Reverse proxy buffering → dinonaktifkan via header `X-Accel-Buffering: no` + konfigurasi nginx (§8).
- Pesan non-JSON di channel → frontend `JSON.parse` dibungkus try/catch, pesan invalid diabaikan.

---

## 7. REST Endpoints

`app/Http/Controllers/NotificationController.php` (semua di grup auth):

- **`index(Request)`** — daftar N terbaru untuk polling fallback & refresh panel. Mengembalikan JSON (dipanggil via `fetch`, bukan kunjungan Inertia):
  ```php
  // `latest()` = orderByDesc('created_at'); deterministik karena created_at mikrodetik (§3.1, Finding 8).
  $user = $request->user();
  $items = $user->notifications()->latest()->limit(20)->get()
      ->map(fn ($n) => [
          'id' => $n->id,
          'type' => $n->data['type'] ?? $n->type,
          'message' => $n->data['message'] ?? '',
          'url' => $n->data['url'] ?? '#',
          'read_at' => $n->read_at?->toIso8601String(),
          'created_at' => $n->created_at->toIso8601String(),
      ]);
  return response()->json([
      'notifications' => $items,
      'has_unread' => $user->unreadNotifications()->exists(),
  ]);
  ```
  (`unreadNotifications()` disediakan trait `Notifiable`.)
- **`markRead(Request, \App\Models\DatabaseNotification $notification)`** — otorisasi kepemilikan: `abort_unless($notification->notifiable_id === $request->user()->id && $notification->notifiable_type === User::class, 403)`. Lalu `$notification->markAsRead()`. Response: untuk klik dari panel (navigasi dilakukan di klien) kembalikan `response()->noContent()` (204); klien lalu `router.visit(url)`. Dipilih 204 + navigasi klien agar deep-link memakai transisi Inertia (tetap SPA), bukan redirect server.
- **`markAllRead(Request)`** — `$request->user()->unreadNotifications->markAsRead()`; `response()->noContent()`.

Validasi input: `index` tanpa parameter (limit tetap 20). Binding `{notification}` otomatis 404 bila UUID tidak ada. Tidak ada input user bebas selain route param UUID (tervalidasi oleh binding).

Error handling REST:
- Notifikasi milik orang lain → 403 (fatal untuk request itu, tidak di-log sebagai error aplikasi; percobaan akses lintas-user).
- UUID tidak ditemukan → 404 (binding).
- Kegagalan DB saat `markAsRead` → exception Laravel standar (500); jarang, tidak di-swallow.

---

## 8. Shared-prop Injection (`HandleInertiaRequests::share()`)

Tambahkan blok `notifications` mengikuti bentuk `auth`/`flash` yang ada (closure agar lazy). Di dalam `share()`:

```php
// `latest()` deterministik (created_at mikrodetik, §3.1 / Finding 8).
'notifications' => fn () => $request->user() ? (function ($u) {
    $items = $u->notifications()->latest()->limit(15)->get()->map(fn ($n) => [
        'id' => $n->id,
        'type' => $n->data['type'] ?? $n->type,
        'message' => $n->data['message'] ?? '',
        'url' => $n->data['url'] ?? '#',
        'read_at' => $n->read_at?->toIso8601String(),
        'created_at' => $n->created_at->toIso8601String(),
    ]);
    return [
        'items' => $items,
        'has_unread' => $u->unreadNotifications()->exists(),
    ];
})($request->user()) : null,
```

Props terkait: `page.props.notifications.items` (seed panel) & `page.props.notifications.has_unread` (seed titik merah). Menambah dua query ringan per request (satu `limit 15`, satu `EXISTS`) — dapat diterima; keduanya memakai index `notifiable_type/notifiable_id` dari `morphs`.

**Mismatch limit 15 vs 20 adalah DISENGAJA (kontrak, Finding R4-6 / review ke-4 NIT #6).** Shared prop men-seed panel dengan 15 item terbaru (§8, `limit(15)`), sedangkan REST `index`/polling (§7, `limit(20)`) dan `pollOnce()` yang menimpa `items.value = data.notifications` dapat menampilkan hingga 20 item. Jadi setelah satu kali poll/refresh, panel yang semula 15 dapat tumbuh menjadi hingga 20 — ini perilaku yang DISENGAJA (seed ringan di setiap request Inertia, daftar lebih panjang hanya saat panel aktif di-poll), BUKAN bug. Pembaca di masa depan JANGAN "menyamakan" kedua angka ini mengira itu inkonsistensi.

---

## 9. Frontend

### 9.1 Composable `resources/js/composables/useNotifications.js`

Tanggung jawab tunggal: kelola koneksi SSE, state notifikasi reaktif, dan fallback polling.

**Fakta terverifikasi: proyek memakai PER-PAGE (non-persistent) layout (Finding R4-2 / review ke-4 MEDIUM #2).** Setiap halaman terautentikasi menyetel `defineOptions({ layout: AppLayout })` (bentuk per-page), dan `resources/js/app.js` TIDAK memasang persistent layout (tidak ada `.layout` global pada komponen App, tidak ada callback persistent-layout). Konsekuensi: komponen `AppLayout` di-UNMOUNT lalu di-REMOUNT pada SETIAP navigasi Inertia. Karena itu, jika EventSource dimiliki oleh lifecycle komponen `AppLayout` dan `onUnmounted` memanggil `es.close()`, maka SETIAP navigasi akan menutup + membuka ulang EventSource — mengaduk satu worker php-fpm per navigasi, mematikan reconnect/backoff native EventSource, dan membuat pengguna tanpa stream di tengah navigasi. Draf sebelumnya hanya melakukan hedging ("jika tidak persistent, modul-singleton mencegah koneksi ganda") — itu DIHAPUS dan diputuskan normatif di bawah.

**Model kepemilikan NORMATIF: EventSource dimiliki di SCOPE MODUL dengan guard `initialized`, SURVIVE lintas navigasi SPA.** EventSource (dan state reaktif) hidup sebagai singleton tingkat modul `useNotifications.js`, dibuat SEKALI per sesi halaman SPA melalui guard `initialized`, dan TIDAK ditutup di `onUnmounted` komponen `AppLayout`. Teardown nyata (`es.close()`) HANYA terjadi pada keluar-app sungguhan, yaitu listener `window` `beforeunload` (dan `pagehide`). Dengan begitu satu EventSource bertahan melintasi semua navigasi Inertia; `AppLayout` yang remount tiap navigasi hanya memanggil `init()` yang menjadi no-op setelah pertama kali (karena `initialized`). Ini juga mengapa `es.close()` pada `onerror` tetap DILARANG (biar reconnect native jalan): satu-satunya penutup adalah teardown app-exit.

State (reactive, modul-singleton tingkat modul):
- `items` (array) — seed dari `page.props.notifications.items`.
- `hasUnread` (boolean) — seed dari `page.props.notifications.has_unread`.
- `initialized` (boolean, scope modul) — guard agar `init()` hanya benar-benar menjalankan seed + `openSse()` + pasang listener sekali per sesi.

**Model reconnect (Finding 5 — NORMATIF).** EventSource native sudah auto-reconnect selama `es.close()` TIDAK dipanggil. Dipilih model: **biarkan EventSource menangani reconnect SSE; polling hanyalah timer yang jalan selama SSE belum tersambung.** Jadi `onerror` TIDAK memanggil `es.close()` (kecuali teardown); ia hanya menyalakan polling. `onopen` mematikan polling. Ini menghindari masalah "SSE one-shot per page load" yang ditemukan review (di mana `es.close()` di `onerror` mematikan reconnect bawaan). Heartbeat server dikirim sebagai komentar SSE (`: ping`) yang TIDAK memicu `onmessage` — itu disengaja; yang mematikan polling adalah `onopen` (saat koneksi benar-benar terbuka), bukan `onmessage`.

```js
import { router, usePage } from '@inertiajs/vue3';

let es = null;            // EventSource
let pollTimer = null;     // fallback interval id
const POLL_MS = 60000;

// Finding 4: CSRF untuk raw fetch. Cookie XSRF-TOKEN URL-encoded → wajib decodeURIComponent.
function csrfHeader() {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return m ? { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) } : {};
}

function openSse() {
  if (es) return;                         // cegah koneksi ganda
  es = new EventSource(route('notifications.stream'));
  es.onopen = () => stopPolling();        // SSE tersambung → matikan fallback
  es.onmessage = (e) => {
    try { handleSignal(JSON.parse(e.data)); } catch { /* abaikan non-JSON */ }
  };
  es.onerror = () => {
    // JANGAN close: biarkan EventSource reconnect sendiri. Nyalakan polling sementara terputus.
    // startPolling() me-return lebih awal bila tab hidden; visibilitychange yang akan
    // memasang kembali timer saat tab kembali terlihat (lihat guard Finding 2 di bawah).
    startPolling();
  };
}

function handleSignal(sig) {
  // 1) titik merah + prepend ke panel
  if (!items.value.find((i) => i.id === sig.id)) items.value.unshift({ ...sig, read_at: null });
  hasUnread.value = true;
  // 2) idle-update konservatif bila halaman relevan
  reloadIfRelevant(sig);
}
```

Strategi `router.reload({ only: [...] })` konservatif per halaman (berdasar `page.component` saat ini) — hanya me-reload prop yang terdampak, bukan seluruh halaman:

| Halaman (component) | Kondisi relevan | `only` |
|---|---|---|
| `Dashboard` | selalu (ringkasan status berubah untuk peran apa pun) | `['katimker','kabid','admin']` (yang ada saja) |
| `Kabid/ReviewList` | sinyal `task.submitted` | `['tugas']` |
| `Kabid/ReviewDetail` | `sig.task_id === props.tugas.id` | `['tugas']` |
| `Katimker/TaskList` | `task.approved/revisi/rejected/assigned` | `['tugas']` |
| `Katimker/TaskDetail` | `sig.task_id === props.tugas.id` | `['tugas']` |

Default: jika halaman tidak ada di tabel, jangan reload data (hanya update lonceng). Nama prop diambil dari controller yang sudah ada (`tugas` di list/detail; blok peran di Dashboard). `page.component` & `page.props` diakses lewat `usePage()`.

**Guard list-vs-detail (NORMATIF, Finding R4-5 / review ke-4 NIT #5).** Diverifikasi terhadap controller: `Kabid/ReviewList` dan `Katimker/TaskList` mengirim `tugas` sebagai ARRAY/collection tanpa `.id` tingkat-atas (`ReviewController::index` me-`map` daftar; `TaskController::index` mengirim paginator), sedangkan `Kabid/ReviewDetail` dan `Katimker/TaskDetail` mengirim `tugas` sebagai OBJEK dengan `.id`. Karena itu `reloadIfRelevant(sig)` TIDAK BOLEH memakai satu guard tunggal yang men-dereference `props.tugas.id` — pada komponen LIST, `props.tugas` adalah array sehingga `.id` adalah `undefined` dan perbandingan `sig.task_id === props.tugas.id` tidak sahih (serta berisiko bila kode diubah menjadi akses yang melempar). Aturan: pada komponen LIST (`Kabid/ReviewList`, `Katimker/TaskList`) reload berdasar `page.component` + tipe sinyal SAJA, tanpa menyentuh `props.tugas.id`; HANYA pada komponen DETAIL (`Kabid/ReviewDetail`, `Katimker/TaskDetail`) bandingkan `sig.task_id === props.tugas.id`. Implementasi `reloadIfRelevant` harus bercabang pada `page.component` (bukan menebak bentuk `props.tugas`).

Fallback polling (Finding 6: guard content-type agar redirect 302→HTML tidak memicu `res.json()` yang gagal; `credentials: 'same-origin'` agar cookie sesi terkirim):
```js
function startPolling() {
  if (pollTimer || document.hidden) return;
  pollTimer = setInterval(pollOnce, POLL_MS);
  pollOnce();
}
function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

async function pollOnce() {
  if (document.hidden) return;
  try {
    const res = await fetch(route('notifications.index'), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const ct = res.headers.get('content-type') || '';
    // Admin mid-enrollment 2FA / sesi kedaluwarsa → 302 ke HTML. Bukan JSON → berhenti diam-diam.
    if (!res.ok || !ct.includes('application/json')) return;
    const data = await res.json();
    items.value = data.notifications;
    hasUnread.value = data.has_unread;
  } catch { /* jaringan gagal → coba lagi interval berikutnya */ }
}
```

Visibility (Finding 5 + Finding 2 revisi ke-3). Masalah yang ditemukan review ke-3: bila SSE error saat tab HIDDEN, `onerror`→`startPolling()` me-return lebih awal (tab hidden) sehingga TIDAK ada timer terpasang; dan handler lama hanya memanggil `openSse()` saat `!es`, padahal `es` masih non-null (tak pernah ditutup) — akibatnya saat tab kembali terlihat, tidak ada SSE OPEN dan tidak ada polling → `has_unread` basi (merusak FR-8). Perbaikan NORMATIF: pada cabang terlihat, **(re)start polling kapan pun SSE tidak sedang OPEN**, lepas dari apakah `es` ada; polling digerakkan oleh `es.readyState`, bukan oleh flag nullable.

```js
function onVisibilityChange() {
  if (document.hidden) { stopPolling(); return; }
  // Tab kembali terlihat:
  if (!es) openSse();                               // belum pernah ada koneksi → buka
  // Jaring pengaman: selama SSE belum OPEN (CONNECTING/CLOSED/belum reconnect),
  // pastikan polling berjalan agar titik merah tetap mutakhir. onopen akan mematikannya.
  if (!es || es.readyState !== EventSource.OPEN) startPolling();
}
// Dipasang SEKALI di init() (lihat Lifecycle di bawah), dilepas di teardown() app-exit.
```

`onopen` tetap satu-satunya yang memanggil `stopPolling()` saat SSE benar-benar terbuka; dengan guard `readyState` di atas, setiap transisi tab→terlihat selalu memasang kembali timer polling bila SSE belum OPEN. Dengan demikian halaman tidak akan pernah berada di keadaan "tanpa SSE DAN tanpa polling".

Lifecycle (NORMATIF, Finding R4-2): `init()` dipanggil `onMounted` di `AppLayout.vue` tetapi DIJAGA guard `initialized` — pada mount pertama ia men-seed state dari shared prop, memanggil `openSse()`, memasang listener `visibilitychange` + `beforeunload`/`pagehide`, lalu set `initialized = true`; pada mount berikutnya (tiap navigasi SPA remount `AppLayout`) `init()` adalah NO-OP. `onUnmounted` komponen `AppLayout` TIDAK memanggil `es.close()` dan TIDAK menyetel `es = null` — EventSource sengaja dibiarkan hidup melintasi navigasi. Satu-satunya teardown (`es.close(); es = null; stopPolling()` + lepas listener) dipicu oleh listener `window` `beforeunload`/`pagehide` (keluar-app sungguhan). `onerror` juga tidak pernah menutup (biar reconnect native jalan). Fallback polling menjaga titik merah tetap muncul (≤60 dtk) selama SSE benar-benar down.

```js
let initialized = false;

function init(page) {
  if (initialized) return;              // guard: sekali per sesi SPA
  initialized = true;
  // seed state dari shared prop
  items.value = page?.props?.notifications?.items ?? [];
  hasUnread.value = page?.props?.notifications?.has_unread ?? false;
  openSse();
  document.addEventListener('visibilitychange', onVisibilityChange);
  window.addEventListener('beforeunload', teardown);
  window.addEventListener('pagehide', teardown);
}

function teardown() {                    // HANYA pada keluar-app sungguhan
  if (es) { es.close(); es = null; }
  stopPolling();
  document.removeEventListener('visibilitychange', onVisibilityChange);
  window.removeEventListener('beforeunload', teardown);
  window.removeEventListener('pagehide', teardown);
  initialized = false;
}
```

Mark-read dari klien (Finding 4: `csrfHeader()` + `credentials`; Finding 7: `item.url` sudah relatif dari server):
```js
async function markRead(item) {
  await fetch(route('notifications.read', item.id), {
    method: 'POST',
    headers: { Accept: 'application/json', ...csrfHeader() },
    credentials: 'same-origin',
  });
  item.read_at = new Date().toISOString();
  hasUnread.value = items.value.some((i) => !i.read_at);
  router.visit(item.url);  // deep-link relatif via Inertia (SPA)
}
async function markAllRead() {
  await fetch(route('notifications.read-all'), {
    method: 'POST',
    headers: { Accept: 'application/json', ...csrfHeader() },
    credentials: 'same-origin',
  });
  items.value.forEach((i) => (i.read_at = i.read_at ?? new Date().toISOString()));
  hasUnread.value = false;
}
```

### 9.2 Komponen lonceng di `AppTopbar.vue`

Sisipkan tombol lonceng di `div.layout-config-menu` SEBELUM tombol tema/pengguna, mempertahankan gaya Sakai (jangan restyle topbar). Pakai `OverlayBadge severity="danger"` tanpa `value` (titik merah) membungkus `Button` ikon `pi pi-bell`, dan `Popover` PrimeVue untuk riwayat.

```vue
<OverlayBadge v-if="hasUnread" severity="danger">
  <button class="layout-topbar-action" @click="toggle" aria-label="Notifikasi"><i class="pi pi-bell" /></button>
</OverlayBadge>
<button v-else class="layout-topbar-action" @click="toggle" aria-label="Notifikasi"><i class="pi pi-bell" /></button>

<Popover ref="op">
  <div class="w-80">
    <div class="flex items-center justify-between mb-2">
      <span class="font-medium">Notifikasi</span>
      <Button link size="small" label="Tandai semua terbaca" @click="markAllRead" />
    </div>
    <ul class="max-h-80 overflow-auto">
      <li v-for="n in items" :key="n.id">
        <button class="w-full text-left p-2 rounded hover:bg-surface-100 dark:hover:bg-surface-800"
                :class="{ 'font-semibold': !n.read_at }" @click="onClick(n)">
          <div class="text-sm">{{ n.message }}</div>
          <div class="text-xs text-muted-color">{{ relativeTime(n.created_at) }}</div>
        </button>
      </li>
      <li v-if="!items.length" class="p-3 text-sm text-muted-color">Belum ada notifikasi.</li>
    </ul>
  </div>
</Popover>
```

- `OverlayBadge`/`Popover` adalah komponen PrimeVue; keduanya ADA di versi terpasang **PrimeVue 4.5.5** (`package.json`: `"primevue": "^4.5.5"`, tema `@primeuix/themes` ^2.0.3) — dikonfirmasi pada pass ini (Finding 13). Auto-import global via `unplugin-vue-components` + `@primevue/auto-import-resolver` (proyek sudah memakai `Button`, `Tag`, `Toast`, `ConfirmDialog` tanpa import eksplisit), jadi `OverlayBadge`/`Popover` juga ter-resolve otomatis tanpa import.
- `items`/`hasUnread`/`markAllRead`/`onClick` berasal dari composable (`useNotifications`) via import.
- `onClick(n)` → `markRead(n)` lalu Popover `hide()`.

Keputusan "titik merah vs angka": pengguna eksplisit meminta titik merah tanpa angka → gunakan `OverlayBadge` dot (tanpa `value`) atau titik `<span>` absolut kecil; dipilih `OverlayBadge severity="danger"` tanpa `value` karena sudah merender dot bulat merah dan menjaga tampilan Sakai.

### 9.3 Inisialisasi di `AppLayout.vue`

Di `<script setup>` `AppLayout.vue`, import `useNotifications` dan panggil `init(usePage())` pada `onMounted`. **JANGAN** memanggil `teardown()`/`es.close()` pada `onUnmounted` komponen ini. Fakta terverifikasi (Finding R4-2): proyek memakai PER-PAGE layout — setiap halaman menyetel `defineOptions({ layout: AppLayout })` dan `app.js` tidak memasang persistent layout, sehingga `AppLayout` di-unmount/remount pada SETIAP navigasi Inertia. Karena itu kepemilikan EventSource dipindah ke SCOPE MODUL `useNotifications.js` dengan guard `initialized` (§9.1): `init()` menjadi no-op setelah pertama kali, dan EventSource SURVIVE lintas navigasi SPA (satu koneksi per sesi, bukan satu per navigasi). Teardown (`es.close()`) HANYA dipicu listener `window` `beforeunload`/`pagehide` yang dipasang di dalam `init()` — bukan `onUnmounted` layout. Dengan model ini tidak ada churn tutup/buka EventSource per navigasi dan reconnect native EventSource tetap efektif.

---

## 10. Docker / nginx / php-fpm & Env

### 10.1 nginx (`docker/nginx/default.conf`)

Tambahkan lokasi khusus SSE agar buffering mati & timeout panjang, tanpa mengubah `location ~ \.php$` umum:

```nginx
# Letakkan SEBELUM `location ~ \.php$`. /sse/notifications dirutekan Laravel
# via index.php, jadi SCRIPT_FILENAME ditunjuk eksplisit ke index.php.
location = /sse/notifications {
    fastcgi_pass app:9000;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param REQUEST_URI $request_uri;    # agar Laravel mengenali path/route
    fastcgi_buffering off;          # jangan buffer stream (override fastcgi_buffers global)
    fastcgi_read_timeout 3600s;     # koneksi SSE panjang (default 300 terlalu pendek)
    gzip off;                       # jangan gzip event-stream (gzip on secara global)
    add_header X-Accel-Buffering no;
}
```
`fastcgi_buffering off` menonaktifkan buffering nginx→php-fpm untuk lokasi ini; `gzip off` penting karena `default.conf` saat ini menyetel `gzip on` global (kompresi akan menahan stream). Jika di masa depan ada layer proxy terminating di depan nginx, tambahkan `proxy_buffering off` + `proxy_read_timeout` di layer itu.

### 10.2 php-fpm (`docker/php/php-fpm.conf`)

- Tambah `request_terminate_timeout = 3700` (sedikit di atas nginx read-timeout) agar worker SSE tidak dibunuh di tengah koneksi sah, namun tetap punya batas atas.
- Naikkan kapasitas worker agar koneksi SSE yang menahan satu worker per klien tidak menghabiskan pool. Setiap klien SSE memegang satu proses php-fpm selama koneksi terbuka. Dengan `pm.max_children = 50`, 50 klien SSE bersamaan akan memenuhi pool. Rekomendasi: naikkan `pm.max_children` sesuai estimasi pengguna aktif bersamaan (mis. 150–300) dan sesuaikan RAM; atau sediakan pool php-fpm terpisah khusus SSE. Keputusan: dokumentasikan dan naikkan `pm.max_children` ke nilai yang aman untuk jumlah pengguna TUKIMAN (mis. 128) pada iterasi ini; pemisahan pool dicatat sebagai peningkatan opsional.
- `set_time_limit(0)` sudah di-set di controller; `max_execution_time` php-fpm tidak membatasi proses yang menunggu I/O stream, namun `request_terminate_timeout` tetap sebagai jaring pengaman.

### 10.3 Env keys (tambah ke `.env.example`)

```dotenv
# ─── Realtime notifications (SSE + Redis pub/sub) ───
# Koneksi Redis TERDEDIKASI untuk subscribe/publish notifikasi (lepas dari cache/session).
REDIS_NOTIFY_DB=2
# Interval heartbeat SSE (detik). Read-timeout subscribe loop TETAP 1 dtk (konstanta loop, §6.2).
SSE_HEARTBEAT_SECONDS=20
# Produksi (app di Docker, Redis+MySQL di host): arahkan REDIS_HOST ke host.
# Linux Docker: tambahkan extra_hosts host.docker.internal:host-gateway di compose,
# atau set REDIS_HOST ke IP host.
# REDIS_HOST=host.docker.internal
```

Tambahkan koneksi `notifications` di `config/database.php` → `redis` (di sebelah `default`/`cache`):

```php
'notifications' => [
    'url' => env('REDIS_URL'),
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('REDIS_NOTIFY_DB', '2'),
    'read_timeout' => env('SSE_HEARTBEAT_SECONDS', 20),
    // Finding 2: kosongkan prefix agar nama channel pub/sub literal `notif.user.{id}`.
    // phpredis menerapkan `options.prefix` global (default `{app-slug}-database-`) ke
    // nama channel publish/subscribe. Prefix per-koneksi menimpa yang global.
    'options' => ['prefix' => ''],
],
```

Keputusan prefix (Finding 2): dipilih **opsi (b)** — set `'options' => ['prefix' => '']` pada koneksi `notifications` sehingga channel literal. Alasan dipilih di atas opsi (a) "dokumentasikan prefix": nama literal lebih mudah di-observasi operator (`redis-cli SUBSCRIBE notif.user.123` langsung bekerja), menghindari jebakan verifikasi palsu, dan publisher/subscriber tetap konsisten karena keduanya lewat koneksi `notifications` yang sama. Catatan pemakaian `read_timeout` (direvisi di pass ke-3): nilai `config('database.redis.notifications.read_timeout')` (= `SSE_HEARTBEAT_SECONDS`, default 20) sekarang menjadi **interval HEARTBEAT** `$hb` di loop luar SSE (§6.2), BUKAN read-timeout raw client. Read-timeout raw client di-set TETAP 1 dtk agar loop deterministik (lihat Finding R3-1). Jadi dua konsep berbeda: `$hb` (20 dtk, dari config) mengatur seberapa sering `: ping` dikirim; read-timeout 1 dtk (konstanta loop) mengatur seberapa cepat `subscribe()` kembali untuk mengecek abort/heartbeat. Nilai `read_timeout` di blok config `notifications` bersifat dokumentatif/legacy untuk koneksi non-stream; loop SSE membacanya hanya sebagai sumber `$hb`.

Catatan produksi (host Redis/MySQL, bukan container): karena arsitektur yang disetujui menaruh Redis & MySQL di host, container `postgres`/`redis` pada `docker-compose.yml` produksi menjadi tidak dipakai untuk fitur ini. Penyelarasan compose produksi (hapus/relasikan container, set `REDIS_HOST`/`DB_HOST` ke host via `extra_hosts: host.docker.internal:host-gateway`, dan ganti driver DB ke MySQL agar konsisten dengan `.env.example`) **dicatat sebagai pekerjaan infrastruktur terkait** namun berada di luar perubahan kode aplikasi; implementor harus mengangkatnya ke pengguna sebelum menyentuh compose produksi.

Nama file yang diverifikasi ada di worktree: `docker/nginx/default.conf` (saat ini `fastcgi_pass app:9000`, blok `location ~ \.php$` memakai `fastcgi_split_path_info` + `fastcgi_buffers`/`fastcgi_buffer_size`, `gzip on` global), `docker/php/php-fpm.conf` (saat ini `pm = dynamic`, `pm.max_children = 50`, `pm.max_requests = 500`, tanpa `request_terminate_timeout`). Edit §10.1/§10.2 mengacu pada file-file ini. Karena `/sse/notifications` BUKAN file `.php` (dirutekan Laravel via `try_files ... /index.php`), blok `location = /sse/notifications` harus menyetel `SCRIPT_FILENAME $document_root/index.php` secara eksplisit (bukan `$fastcgi_script_name`).

### 10.4 Kelayakan dev lokal Windows / `php artisan serve` (Finding 3)

`php artisan serve` adalah server dev PHP **single-proses, single-thread**. Satu koneksi SSE memegang proses itu sepanjang hidupnya, sehingga seluruh app (termasuk halaman Inertia dan fetch polling) tidak responsif selama SSE terbuka. Alternatif seperti `php -S` juga single-proses (batasan sama); `frankenphp`/`roadrunner` multi-proses tetapi bukan setup dev standar proyek ini.

Keputusan & mitigasi (tanpa kode baru — frontend sudah menanganinya):
- Untuk mencoba SSE end-to-end di lokal, jalankan stack Docker (nginx + php-fpm) seperti produksi, yang multi-worker.
- Di `php artisan serve`, SSE sengaja dibiarkan gagal tersambung; `EventSource.onerror` → `startPolling()` (§9.1) sehingga titik merah & riwayat tetap muncul via polling ~60 dtk. Fitur tetap fungsional, hanya tidak instan.
- Dokumentasikan ini di catatan dev (README/komentar) agar developer Windows tidak menyimpulkan "SSE rusak" saat memakai `artisan serve`.

Verifikasi (ditambahkan ke §12.3): di `php artisan serve`, buka app → pastikan halaman tetap responsif (SSE tidak menyambung, tidak memblokir) dan titik merah muncul via polling setelah ada notifikasi; di stack Docker → `curl -N` menunjukkan stream SSE hidup.

---

## 11. Error Handling (ringkasan per operasi)

| Operasi | Kondisi gagal | Recoverable? | Yang diterima pemanggil | Log |
|---|---|---|---|---|
| `NotificationService::notify` resolusi user | user lokal tak ada | ya (lewati) | tidak ada efek; alur utama lanjut | `info` |
| `NotificationService` tulis baris | error DB | tidak | exception naik (jarang; terjadi di luar transaksi inti) | default Laravel |
| `NotificationService::publish` | Redis down/timeout | ya (abaikan) | tidak ada; baris DB tetap tersimpan | `warning` |
| SSE connect/subscribe | Redis down | ya | stream berakhir; klien reconnect + fallback polling | `debug` |
| SSE klien putus | — | ya | loop keluar bersih | — |
| REST markRead milik orang lain | otorisasi | tidak | 403 | — |
| REST `{notification}` tak ada | binding | tidak | 404 | — |
| Frontend `fetch` polling gagal | jaringan/HTTP≠2xx | ya | state tak diubah; coba lagi interval berikutnya | console (opsional) |
| Frontend SSE `onerror` | koneksi putus | ya | tutup + mulai polling | — |

Validasi input eksternal:
- Route param `{notification}` — UUID; divalidasi oleh route-model-binding (404 bila invalid/tak ada). Wajib.
- Body REST — tidak ada (mark-read/all tanpa body).
- Pesan Redis masuk ke frontend — divalidasi `JSON.parse` di klien; non-JSON diabaikan.

Invarian & kepemilikan lapisan:
- **Sumber kebenaran = baris `notifications`** (DB). Dipaksakan di `NotificationService` (satu-satunya penulis). Redis murni sinyal.
- **Kepemilikan notifikasi** (user hanya bisa baca/tandai miliknya) ditegakkan di controller REST (`notifiable_id/type` cocok dengan user auth) dan di query shared-prop/index (selalu `->user()->notifications()`).
- **Pemetaan sikep_user_id → User** ditegakkan di satu tempat (`NotificationService::notify`) agar tidak tersebar.

---

## 12. Test Plan

Konteks test (phpunit.xml): `DB_CONNECTION=sqlite :memory:`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `BROADCAST_CONNECTION=null`. Redis kemungkinan tidak tersedia di CI → publish harus best-effort. Pola mengikuti `KabidReviewTest` (mock `RoleResolver`, `User::factory`, `TaskReport::create`).

Agar Redis tidak mengganggu test, `NotificationService::publish` menangkap semua `Throwable`; test tidak perlu Redis hidup. Opsional: `Redis::shouldReceive('connection->publish')` via facade mock untuk menegaskan dipanggil, tetapi tidak wajib (kegagalan tetap aman).

Ordering di test (Finding 8): assert keanggotaan (`assertDatabaseHas`, cek `data.type`/`notifiable_id`) dan jumlah (`assertDatabaseCount`); hindari assertion yang bergantung pada urutan antar dua notifikasi yang dibuat dalam mikrodetik yang sama. Panel/`index` urut `created_at` mikrodetik (model kustom §3.1) — cukup deterministik untuk kasus nyata.

### 12.1 Feature tests baru

Buat `tests/Feature/NotificationTest.php`:

1. `test_approve_membuat_notifikasi_untuk_katimker_pemilik` — Kabid approve → `assertDatabaseHas('notifications', ['notifiable_id' => $katimkerUser->id, 'type' => 'task.approved'])`; cek `data.url` mengandung `/katimker/tugas/{id}` dan `notifiable_type = User::class`. Prasyarat: buat `User` lokal dengan `sikep_user_id = katimkerId`.
2. `test_revisi_dan_reject_membuat_notifikasi_tepat_tipe` — revisi → `task.revisi`; reject → `task.rejected`, keduanya ke Katimker pemilik.
3. `test_submit_membuat_notifikasi_untuk_kabid` — Katimker submit (`katimker.tugas.store`) → baris untuk User `sikep_user_id = kabidId`, `type = task.submitted`, `data.url` mengandung `/kabid/penilaian/{id}`. Perlu `ApproverMapping` aktif + `Activity` aktif + periode terbuka (ikuti pola `KatimkerSubmitTest`).
4. `test_update_dari_revisi_mengirim_notifikasi_tetapi_update_diajukan_tidak` — update tugas `revisi` → ada notifikasi submit; update tugas `diajukan` → TIDAK ada notifikasi baru (`assertDatabaseCount('notifications', n)` sebelum/sesudah).
5. `test_penugasan_membuat_notifikasi_untuk_katimker` — Kabid `kabid.penugasan.store` → baris `task.assigned` untuk Katimker, pesan "Penugasan baru menunggu bukti".
6. `test_mark_read_mengubah_read_at` — buat notifikasi untuk user auth; POST `notifications.read` → `read_at` non-null; `assertDatabaseHas` dengan `whereNotNull`.
7. `test_mark_read_milik_user_lain_ditolak` — notifikasi milik user lain → POST → 403, `read_at` tetap null.
8. `test_mark_all_read` — dua notifikasi belum dibaca → POST `notifications.read-all` → keduanya `read_at` non-null.
9. `test_shared_prop_has_unread_mencerminkan_status` — kunjungi halaman Inertia; `assertInertia(fn($p)=>$p->where('notifications.has_unread', true))` saat ada unread; `false` setelah mark-all.
10. `test_notifikasi_tetap_dibuat_saat_user_lokal_penerima_tidak_ada` — approve tugas yang `katimker_sikep_user_id`-nya tak punya User lokal → tidak ada exception, tidak ada baris (dilewati aman), request tetap redirect sukses.
11. `test_sse_endpoint_butuh_auth` — GET `notifications.stream` tanpa auth → redirect ke login; (untuk auth, cukup assert status 200 + header `Content-Type: text/event-stream` dengan koneksi yang segera di-abort; jika streaming sulit diuji di PHPUnit, uji minimal header via response factory atau tandai sebagai uji manual).

Catatan: endpoint SSE yang benar-benar streaming sulit diuji penuh di PHPUnit (loop blocking). Cakupan uji otomatis difokuskan pada: pembuatan baris notifikasi (unit via service + feature via controller), mark-read/all, otorisasi, dan shared-prop. Loop SSE & nginx buffering diverifikasi manual (lihat §12.3).

### 12.2 Unit test opsional

`tests/Unit/NotificationServiceTest.php` — `notify()` dengan user ada → baris dibuat; user tak ada → tidak ada baris, tidak error; publish Redis gagal (facade mock melempar) → `notify()` tetap sukses dan baris ada.

### 12.3 Verifikasi manual (tidak diotomasi)

- Buka dua browser (Kabid & Katimker). Kabid approve → titik merah + item muncul di lonceng Katimker tanpa refresh; bila Katimker sedang di `Katimker/TaskList`/`TaskDetail`, data ter-reload via `router.reload`.
- Matikan Redis → titik merah tetap muncul dalam ≤60 dtk via polling; hidupkan lagi → SSE pulih (EventSource reconnect, `onopen` matikan polling).
- Di Docker: `curl -N https://.../sse/notifications` menunjukkan `: connected` lalu heartbeat `: ping` tiap ~20 dtk tanpa buffering nginx.
- Re-subscribability setelah idle (Finding R4-1 / AC #12): dengan `curl -N` tetap terbuka, DIAMKAN channel ≥60 dtk sampai muncul ≥3 `: ping`, lalu picu event nyata (mis. Kabid approve, atau `redis-cli -n <REDIS_NOTIFY_DB> PUBLISH notif.user.<id> '{"id":"x","type":"task.approved","message":"tes"}'`). Harapan: baris `data: {...}` MUNCUL di `curl` meski koneksi sudah melewati banyak read-timeout/rebuild. Jika pesan TIDAK muncul (hanya ping terus), berarti `subscribe()` tidak re-subscribable pada build itu — periksa jalur rebuild di §6.2.
- Observasi channel (Finding 2): karena koneksi `notifications` memakai `prefix => ''`, `redis-cli SUBSCRIBE notif.user.<sikep_user_id>` LANGSUNG menerima pesan JSON saat ada event (nama literal, tanpa prefix `{app-slug}-database-`). Jika tidak menerima apa pun, periksa `REDIS_NOTIFY_DB` cocok dengan `-n` redis-cli.
- Dev Windows (Finding 3): dengan `php artisan serve`, buka app → halaman tetap responsif (SSE tidak menyambung dan tidak memblokir karena `onerror`→polling), titik merah muncul via polling ≤60 dtk setelah ada notifikasi. SSE end-to-end diverifikasi di stack Docker.

### 12.4 Perintah verifikasi

- `./vendor/bin/pint` (format file baru).
- `php artisan test --filter=Notification` lalu `php artisan test` (regресi penuh).
- `npm run build` (pastikan komponen lonceng & composable ter-compile).

---

## 13. Ringkasan File yang Disentuh

Baru:
- `database/migrations/<timestamp-saat-implementasi>_create_notifications_table.php` (nama harus mengurut setelah `2026_10_09_100002`; pakai tanggal implementasi — tak ada FK ke `users` sehingga urutan tidak load-bearing)
- `app/Models/DatabaseNotification.php` (subclass dengan `$dateFormat` mikrodetik, §3.1 / Finding 8)
- `app/Services/NotificationService.php`
- `app/Http/Controllers/NotificationController.php`
- `app/Http/Controllers/NotificationStreamController.php`
- `resources/js/composables/useNotifications.js`
- `tests/Feature/NotificationTest.php` (+ opsional `tests/Unit/NotificationServiceTest.php`)

Diubah:
- `routes/web.php` (4 route di grup auth)
- `app/Models/User.php` (override `notifications()` → model notifikasi kustom, §3.1 / Finding 8)
- `config/database.php` (koneksi redis `notifications` + `options.prefix => ''`)
- `app/Http/Middleware/HandleInertiaRequests.php` (shared prop `notifications`)
- `app/Services/TaskService.php`, `app/Services/ReviewService.php`, `app/Services/AssignmentService.php` (inject + panggil `NotificationService` pasca-commit)
- `resources/js/layout/AppTopbar.vue` (lonceng + Popover)
- `resources/js/Layouts/AppLayout.vue` (init composable)
- `docker/nginx/default.conf`, `docker/php/php-fpm.conf`
- `.env.example` (env keys baru)

Catatan untuk implementor: dua ketidaksesuaian infrastruktur (compose produksi memakai PostgreSQL+container Redis vs arsitektur yang disetujui MySQL+host Redis) berada DI LUAR perubahan kode aplikasi dan harus dikonfirmasi ke pengguna sebelum menyentuh file compose produksi.

---

## 14. Tanggapan atas Temuan Review (`design-review.json`, verdict CHANGES_REQUESTED)

### 14.-1 Review ke-4 (terbaru: 0 HIGH, 2 MEDIUM, 4 NIT — semua ditutup)

Review ke-4 memverifikasi hasil pass ke-3 terhadap source worktree dan menandai 2 MEDIUM (runtime SSE + lifecycle layout) serta 4 NIT. Semua ditutup secara normatif di pass ini; setiap temuan diverifikasi ulang terhadap source aktual (per-page layout di `defineOptions`, `app.js` tanpa persistent layout, bentuk prop `tugas` list vs detail di controller, dan tidak adanya morph map di `app/`).

| # | Sev | Judul singkat | Status | Penyelesaian & lokasi edit |
|---|---|---|---|---|
| R4-1 | MED | Loop SSE me-`subscribe()` ulang pada koneksi SAMA setelah read-timeout `RedisException` → socket subscriber bisa rusak → error-spin hanya heartbeat, pesan tak pernah terkirim | **Diperbaiki** | §6.2: pada `catch (\RedisException)` koneksi di-DISCARD + REBUILD (`Redis::purge('notifications')` → `Redis::connection('notifications')` → set ulang `OPT_READ_TIMEOUT=1`) sebelum `subscribe()` berikutnya; helper `$connect()` dalam closure stream. Prosa normatif + catatan beban (boleh naikkan read-timeout mendekati `$hb`). AC baru #12 (§1.4) + verifikasi manual re-delivery setelah ≥60 dtk idle (§12.3). |
| R4-2 | MED | `AppLayout` adalah PER-PAGE layout (non-persistent) → `onUnmounted` `es.close()` menutup+membuka EventSource tiap navigasi Inertia | **Diperbaiki** | §9.1: model kepemilikan EventSource dipindah ke SCOPE MODUL dengan guard `initialized`; `init()` no-op setelah pertama, EventSource SURVIVE lintas navigasi; teardown HANYA via `beforeunload`/`pagehide`, BUKAN `onUnmounted` layout. §9.3 dinyatakan ulang: proyek memakai per-page layout (terverifikasi), `AppLayout` jangan panggil `teardown()`/`es.close()` di `onUnmounted`. |
| R4-3 | NIT | Jaminan write-path `created_at` mikrodetik bergantung timestamps model — belum eksplisit | **Diperbaiki** | §3.1: invarian normatif — model kustom mempertahankan `$timestamps = true`; `created_at` ditulis Eloquent via `$dateFormat`; JANGAN disable `$timestamps` atau override `created_at`/`UPDATED_AT`/`CREATED_AT`. |
| R4-4 | NIT | Perbandingan `notifiable_type` bergantung tiadanya morph map — belum di-pin | **Diperbaiki** | §1.6: asumsi normatif — tidak ada morph map di-enforce (terverifikasi di `app/`), `notifiable_type` = FQCN; jika morph map kelak ada, semua perbandingan `notifiable_type` + assertion test WAJIB pindah ke alias morph. |
| R4-5 | NIT | Guard idle-update bisa men-dereference `props.tugas.id` di halaman LIST (array, tanpa `.id`) | **Diperbaiki** | §9.1: `reloadIfRelevant` WAJIB bercabang pada `page.component`; LIST (`ReviewList`/`TaskList`) reload berdasar tipe sinyal SAJA tanpa `props.tugas.id`; hanya DETAIL (`ReviewDetail`/`TaskDetail`) bandingkan `sig.task_id === props.tugas.id`. |
| R4-6 | NIT | Mismatch limit 15 (shared prop) vs 20 (index/poll) tak terdokumentasi sebagai disengaja | **Diperbaiki** | §8: dinyatakan sebagai kontrak — panel seed 15, polling/refresh dapat menampilkan hingga 20 by design; pembaca jangan "menyamakan". |

### 14.0 Review ke-3 (1 HIGH, 2 MEDIUM, 2 NIT)

Review ke-3 memeriksa hasil pass ke-2 dan masih menemukan sisa gap pada loop SSE dan state-machine frontend. Semua temuan diselesaikan di dalam desain pada pass ke-3 ini; tanggal migrasi diverifikasi ulang terhadap `database/migrations` aktual (`2026_10_09_100002` terakhir, tanggal sistem `2026_10_14`).

| # | Sev | Judul singkat | Status | Penyelesaian |
|---|---|---|---|---|
| R3-1 | HIGH | Heartbeat/abort SSE bergantung perilaku `OPT_READ_TIMEOUT` yang tak terverifikasi; fallback "echo berikutnya" tidak menjaga heartbeat → NFR-5 gagal diam-diam + worker bocor ~1 jam | **Diperbaiki** | §6.2 ditulis ulang: read-timeout DETERMINISTIK 1 dtk + akumulator heartbeat wall-clock di loop luar. Heartbeat & cek abort kini INDEPENDEN dari apakah phpredis melempar — loop luar selalu berputar ≤1 dtk. Kalimat fallback "andalkan echo/flush berikutnya" yang menyesatkan dihapus; satu-satunya precondition tersisa (read-timeout benar-benar membatasi blok) dinyatakan eksplisit + cara verifikasinya di §12.3. |
| R3-2 | MED | Frontend bisa berakhir tanpa SSE DAN tanpa polling setelah tab kembali terlihat saat SSE down (error ketika hidden → tak ada timer; handler hanya `openSse()` saat `!es`) | **Diperbaiki** | §9.1 `visibilitychange`: pada cabang terlihat, `startPolling()` dipanggil kapan pun `!es || es.readyState !== EventSource.OPEN` — polling digerakkan oleh `readyState`, bukan flag nullable. Timer polling selalu terpasang ulang pada tiap transisi tab→terlihat selama SSE belum OPEN. |
| R3-3 | MED | Loop SSE memanggil `$redis->close()` di dalam callback lalu re-enter `subscribe()` pada koneksi mungkin-tertutup; dua jalur unblock → state koneksi tak konsisten | **Diperbaiki** | §6.2: abort HANYA dideteksi di loop luar; callback TIDAK pernah `close()`. Koneksi terdedikasi dibuka SEKALI sebelum loop, dipakai ulang untuk tiap `subscribe()`, ditutup TEPAT SEKALI di `finally`. Satu lifecycle konsisten untuk jalur timeout maupun abort. |
| R3-4 | NIT | Premis "tanggal masa depan" migrasi salah (tanggal sistem `2026_10_14`; existing s.d. `2026_10_09`) | **Diperbaiki** | §3.1 & §13: kata-kata "jangan tanggal masa depan" diganti → "nama harus mengurut setelah `2026_10_09_100002`; pakai tanggal implementasi; tak ada FK ke `users` sehingga urutan tidak load-bearing". |
| R3-5 | NIT | Nyatakan `data.url` punya sumber tunggal agar tak ada regresi URL-absolut di masa depan | **Diperbaiki** | §3.2: aturan NORMATIF "sumber-tunggal `url`" — argumen `$url` dari `route(name,$task,false)` adalah satu-satunya sumber; semua mapper (§7/§8/§5) WAJIB membaca `data.url` dan DILARANG memanggil ulang `route()`. |

### 14.1 Review ke-2 (riwayat: 3 HIGH, 7 MEDIUM, 3 NIT)

Semua temuan HIGH & MEDIUM diselesaikan di dalam desain; NIT ditangani. Verifikasi ulang dilakukan dengan membaca file aktual di worktree pada pass ini.

| # | Sev | Judul singkat | Status | Penyelesaian |
|---|---|---|---|---|
| 1 | HIGH | Loop SSE ganda & bertentangan; `StopStreaming` tak terdefinisi; interaksi read-timeout/`connection_aborted()` | **Diperbaiki** | §6.2 ditulis ulang: SATU pola normatif `while (! connection_aborted())` + `subscribe()` blocking yang di-unblock oleh `OPT_READ_TIMEOUT` (lempar `RedisException`) di loop luar; heartbeat & cek abort di loop luar; `StopStreaming` dihapus; dependensi perilaku phpredis dinyatakan eksplisit untuk diverifikasi implementor. |
| 2 | HIGH | Koneksi Redis `notifications` mewarisi `options.prefix` global → nama channel ter-prefix diam-diam | **Diperbaiki** | §10.3: koneksi `notifications` di-set `'options' => ['prefix' => '']` (opsi b, dengan alasan). §5 & §12.3 diselaraskan agar `redis-cli SUBSCRIBE notif.user.X` memakai nama literal. Diverifikasi `config/database.php` memang punya prefix global. |
| 3 | HIGH | `php artisan serve` single-proses memblokir app saat SSE; tak direkonsiliasi dgn NFR-3 | **Diperbaiki** | §10.4 baru + catatan NFR-3: dev lokal pakai stack Docker untuk SSE; di `artisan serve` SSE sengaja gagal → fallback polling tetap jalan (tanpa kode baru). Verifikasi ditambah di §12.3. |
| 4 | MED | CSRF raw `fetch`: wajib `decodeURIComponent(XSRF-TOKEN)` + credentials | **Diperbaiki** | §9.1: helper `csrfHeader()` dengan `decodeURIComponent`, dan `credentials: 'same-origin'` di markRead/markAll/poll. |
| 5 | MED | Kepemilikan reconnect SSE tak konsisten; `es.close()` di `onerror` membuat SSE one-shot | **Diperbaiki** | §9.1: model normatif — `onerror` TIDAK menutup (biar EventSource auto-reconnect), hanya menyalakan polling; `onopen` matikan polling; `visibilitychange` memanggil `openSse()` bila `!es`. |
| 6 | MED | Admin belum-2FA → 302; `pollOnce` panggil `res.json()` tanpa guard content-type | **Diperbaiki** | §6.1 catatan admin-2FA (dalam Out of Scope); §9.1 `pollOnce` guard `content-type` + `res.ok`. |
| 7 | MED | `route()` absolut default → `router.visit` bisa rusak bila `APP_URL` ≠ origin | **Diperbaiki** | §3.2/§4: semua URL dibangun `route(name, $task, false)` (relatif). Tabel §4 diperbarui ke bentuk `absolute:false`. |
| 8 | MED | `latest()` tak deterministik untuk UUID-PK dlm 1 detik | **Diperbaiki** | §3.1: model notifikasi kustom `App\Models\DatabaseNotification` dgn `$dateFormat = 'Y-m-d H:i:s.u'` + kolom `timestamp(col,6)` + override `User::notifications()`. (Diverifikasi `Model::$useDateTimeWithMicroseconds` TIDAK ada di Laravel 13 → rekomendasi draf lama dikoreksi.) |
| 9 | MED | Alur notify penugasan harus cocok `ReviewController::assignStore` (method-inject, return tak ditangkap) | **Diperbaiki** | §5.1: eksplisit — notify di `AssignmentService::createForKatimker` pasca-commit; controller tetap method-inject tanpa perubahan signature. Diverifikasi dari kode controller. |
| 10 | MED | Pilih satu lapisan (service vs controller) untuk notify, konsisten di 4 service | **Diperbaiki** | §5.1: NORMATIF lapisan **service**, pasca-commit, pakai `$task` yang di-return/refresh. Alasan: `assignStore` tak menangkap return → tak ada titik pasca-commit di controller. |
| 11 | NIT | Nama migrasi bertanggal masa depan (2026_10_10) | **Diperbaiki** | §3.1 & §13: pakai timestamp hari implementasi; placeholder `<timestamp-saat-implementasi>`. |
| 12 | NIT | `ignore_user_abort(false)`/manajemen buffer redundan | **Diperbaiki** | §6.2: satu strategi flushing (akhiri semua buffer SEKALI, lalu helper `sse()` echo+flush); `ignore_user_abort(false)` dihapus. |
| 13 | NIT | Konfirmasi `OverlayBadge`/`Popover` ada di versi PrimeVue terpasang | **Diperbaiki** | §9.2: dikonfirmasi PrimeVue 4.5.5 (keduanya ada di PrimeVue 4) + auto-import. |

Catatan atas "Unverified assumptions" review:
- phpredis `OPT_READ_TIMEOUT`+`subscribe()` (review butir 2): dinyatakan sebagai dependensi yang WAJIB diverifikasi implementor di §6.2, lengkap dengan jalur fallback bila build phpredis tidak melempar.
- Keberadaan `docker/nginx/default.conf` & `docker/php/php-fpm.conf` (review butir 3): dikonfirmasi ADA di worktree pada pass ini; isi aktual dibaca dan §10.1/§10.2 disesuaikan (mis. `gzip on` global → `gzip off` di lokasi SSE; `pm.max_children = 50` saat ini).
- Route-model-binding UUID (review butir 4): tetap diuji di §12.1 #7; binding diarahkan ke model kustom.
