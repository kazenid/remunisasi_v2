<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ApproverMapping;
use App\Models\DatabaseNotification;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Notifikasi realtime: pembuatan baris untuk penerima yang benar (resolusi
 * via sikep_user_id), mark-read/all + otorisasi, shared prop has_unread, dan
 * ketahanan saat user lokal penerima tidak ada. Jalur SSE/Redis diuji manual
 * (desain §12.3) — publish best-effort tidak hard-fail tanpa Redis.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private int $kabidId = 700;

    private int $katimkerId = 500;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockRole(bool $kabid = false, bool $katimker = false): void
    {
        $this->mock(RoleResolver::class, function ($m) use ($kabid, $katimker) {
            $m->shouldReceive('resolve')->andReturn([
                'roles' => array_values(array_filter([
                    $kabid ? 'kabid' : null,
                    $katimker ? 'katimker' : null,
                ])),
                'is_katimker' => $katimker,
                'is_kabid' => $kabid,
                'is_admin' => false,
            ]);
        });
    }

    private function actingAsKabid(?int $sikepUserId = null): User
    {
        $user = User::factory()->create(['sikep_user_id' => $sikepUserId ?? $this->kabidId]);
        $this->mockRole(kabid: true);
        $this->actingAs($user);

        return $user;
    }

    private function actingAsKatimker(?int $sikepUserId = null): User
    {
        $user = User::factory()->create(['sikep_user_id' => $sikepUserId ?? $this->katimkerId]);
        $this->mockRole(katimker: true);
        $this->actingAs($user);

        return $user;
    }

    /** User lokal untuk penerima (agar NotificationService dapat meresolusi). */
    private function userLokal(int $sikepUserId): User
    {
        return User::factory()->create(['sikep_user_id' => $sikepUserId]);
    }

    private function tugasDiajukan(array $overrides = []): TaskReport
    {
        return TaskReport::create(array_merge([
            'katimker_sikep_user_id' => $this->katimkerId,
            'kabid_sikep_user_id' => $this->kabidId,
            'tanggal' => '2026-10-06',
            'jenis' => 'utama',
            'judul' => 'Tugas uji',
            'durasi_diajukan_menit' => 120,
            'status' => 'diajukan',
            'diajukan_pada' => now(),
        ], $overrides));
    }

    public function test_approve_membuat_notifikasi_untuk_katimker_pemilik(): void
    {
        $katimker = $this->userLokal($this->katimkerId);
        $this->actingAsKabid();
        $task = $this->tugasDiajukan();

        $this->post(route('kabid.penilaian.approve', $task->id), [
            'durasi_jam' => 2, 'durasi_menit' => 0,
        ])->assertRedirect();

        $this->assertSame('disetujui', $task->fresh()->status);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $katimker->id,
            'notifiable_type' => User::class,
            'type' => 'task.approved',
        ]);

        $notif = DatabaseNotification::query()->where('type', 'task.approved')->firstOrFail();
        $this->assertStringContainsString('/katimker/tugas/'.$task->id, $notif->data['url']);
    }

    public function test_revisi_dan_reject_membuat_notifikasi_tepat_tipe(): void
    {
        $katimker = $this->userLokal($this->katimkerId);

        $this->actingAsKabid();
        $task = $this->tugasDiajukan();
        $this->post(route('kabid.penilaian.revisi', $task->id), ['keterangan' => 'Lengkapi bukti'])->assertRedirect();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $katimker->id, 'type' => 'task.revisi',
        ]);

        $task2 = $this->tugasDiajukan();
        $this->post(route('kabid.penilaian.reject', $task2->id), ['keterangan' => 'Tidak sesuai'])->assertRedirect();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $katimker->id, 'type' => 'task.rejected',
        ]);
    }

    public function test_submit_membuat_notifikasi_untuk_kabid(): void
    {
        $kabid = $this->userLokal($this->kabidId);
        $this->actingAsKatimker();
        ApproverMapping::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'kabid_sikep_user_id' => $this->kabidId,
        ]);
        $kegiatan = Activity::create(['nama' => 'Visite pasien']);

        $this->post(route('katimker.tugas.store'), [
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'kegiatan_id' => $kegiatan->id,
            'durasi_jam' => 2, 'durasi_menit' => 0,
        ])->assertRedirect();

        $task = TaskReport::firstOrFail();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $kabid->id, 'type' => 'task.submitted',
        ]);
        $notif = DatabaseNotification::query()->where('type', 'task.submitted')->firstOrFail();
        $this->assertStringContainsString('/kabid/penilaian/'.$task->id, $notif->data['url']);
    }

    public function test_update_dari_revisi_mengirim_notifikasi_tetapi_update_diajukan_tidak(): void
    {
        $kabid = $this->userLokal($this->kabidId);
        $this->actingAsKatimker();
        ApproverMapping::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'kabid_sikep_user_id' => $this->kabidId,
        ]);
        $kegiatan = Activity::create(['nama' => 'Visite pasien']);

        // Update tugas yang sudah "diajukan" → TIDAK ada notifikasi.
        $diajukan = $this->tugasDiajukan(['kegiatan_id' => $kegiatan->id, 'judul' => $kegiatan->nama]);
        $this->post(route('katimker.tugas.update', $diajukan->id), [
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'kegiatan_id' => $kegiatan->id,
            'durasi_jam' => 3, 'durasi_menit' => 0,
        ])->assertRedirect();
        $this->assertDatabaseCount('notifications', 0);

        // Update tugas berstatus "revisi" → ada notifikasi submit ke Kabid.
        $revisi = $this->tugasDiajukan([
            'kegiatan_id' => $kegiatan->id, 'judul' => $kegiatan->nama,
            'status' => 'revisi', 'catatan_penilai' => 'Perbaiki',
        ]);
        $this->post(route('katimker.tugas.update', $revisi->id), [
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'kegiatan_id' => $kegiatan->id,
            'durasi_jam' => 2, 'durasi_menit' => 0,
        ])->assertRedirect();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $kabid->id, 'type' => 'task.submitted',
        ]);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_penugasan_membuat_notifikasi_untuk_katimker(): void
    {
        $katimker = $this->userLokal($this->katimkerId);
        $this->actingAsKabid();
        ApproverMapping::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'kabid_sikep_user_id' => $this->kabidId,
        ]);
        $kegiatan = Activity::create(['nama' => 'Tugas tambahan']);

        $this->post(route('kabid.penugasan.store'), [
            'katimker_sikep_user_id' => $this->katimkerId,
            'tanggal' => '2026-10-06',
            'kegiatan_id' => $kegiatan->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $katimker->id, 'type' => 'task.assigned',
        ]);
        $notif = DatabaseNotification::query()->where('type', 'task.assigned')->firstOrFail();
        $this->assertSame('Penugasan baru menunggu bukti.', $notif->data['message']);
    }

    public function test_mark_read_mengubah_read_at(): void
    {
        $user = $this->actingAsKabid();
        $notif = $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'task.submitted',
            'data' => ['type' => 'task.submitted', 'message' => 'Tes', 'url' => '/x'],
        ]);

        $this->post(route('notifications.read', $notif->id))->assertNoContent();

        $this->assertNotNull($notif->fresh()->read_at);
    }

    public function test_mark_read_milik_user_lain_ditolak(): void
    {
        $pemilik = $this->userLokal(999);
        $notif = $pemilik->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'task.submitted',
            'data' => ['type' => 'task.submitted', 'message' => 'Tes', 'url' => '/x'],
        ]);

        $this->actingAsKabid();
        $this->post(route('notifications.read', $notif->id))->assertForbidden();

        $this->assertNull($notif->fresh()->read_at);
    }

    public function test_mark_all_read(): void
    {
        $user = $this->actingAsKabid();
        foreach (range(1, 2) as $i) {
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'task.submitted',
                'data' => ['type' => 'task.submitted', 'message' => "Tes {$i}", 'url' => '/x'],
            ]);
        }

        $this->post(route('notifications.read-all'))->assertNoContent();

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_shared_prop_has_unread_mencerminkan_status(): void
    {
        $user = $this->actingAsKabid();
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'task.submitted',
            'data' => ['type' => 'task.submitted', 'message' => 'Tes', 'url' => '/x'],
        ]);

        $this->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('notifications.has_unread', true));

        $user->unreadNotifications->markAsRead();

        $this->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('notifications.has_unread', false));
    }

    public function test_notifikasi_tetap_dibuat_saat_user_lokal_penerima_tidak_ada(): void
    {
        // Tidak ada User lokal untuk katimkerId → notify dilewati aman.
        $this->actingAsKabid();
        $task = $this->tugasDiajukan();

        $this->post(route('kabid.penilaian.approve', $task->id), [
            'durasi_jam' => 2, 'durasi_menit' => 0,
        ])->assertRedirect();

        $this->assertSame('disetujui', $task->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_sse_endpoint_butuh_auth(): void
    {
        $this->get(route('notifications.stream'))->assertRedirect(route('login'));
    }
}
