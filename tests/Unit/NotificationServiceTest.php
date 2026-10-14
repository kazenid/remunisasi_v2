<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_notify_membuat_baris_saat_user_ada(): void
    {
        $user = User::factory()->create(['sikep_user_id' => 123]);

        app(NotificationService::class)->notify(
            123, 'task.approved', 'Tugas disetujui.', '/katimker/tugas/9', ['task_id' => 9]
        );

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id, 'type' => 'task.approved',
        ]);
    }

    public function test_notify_dilewati_saat_user_tidak_ada(): void
    {
        app(NotificationService::class)->notify(
            404, 'task.approved', 'Tugas disetujui.', '/katimker/tugas/9'
        );

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notify_tetap_sukses_saat_publish_redis_gagal(): void
    {
        $user = User::factory()->create(['sikep_user_id' => 55]);

        // Redis publish melempar → harus ditangkap, baris tetap tersimpan.
        Redis::shouldReceive('connection')
            ->andThrow(new \RuntimeException('Redis down'));

        app(NotificationService::class)->notify(
            55, 'task.assigned', 'Penugasan baru menunggu bukti.', '/katimker/tugas/1', ['task_id' => 1]
        );

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id, 'type' => 'task.assigned',
        ]);
    }
}
