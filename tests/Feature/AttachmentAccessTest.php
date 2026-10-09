<?php

namespace Tests\Feature;

use App\Models\TaskAttachment;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class AttachmentAccessTest extends TestCase
{
    use RefreshDatabase;

    private int $katimkerId = 500;

    private int $kabidId = 700;

    private function mockRoles(array $map): void
    {
        // $map: sikep_user_id => roles[]
        $this->mock(RoleResolver::class, function ($m) use ($map) {
            $m->shouldReceive('resolve')->andReturnUsing(function (int $id) use ($map) {
                $roles = $map[$id] ?? [];

                return [
                    'roles' => $roles,
                    'is_katimker' => in_array('katimker', $roles, true),
                    'is_kabid' => in_array('kabid', $roles, true),
                    'is_admin' => in_array('admin', $roles, true),
                ];
            });
        });
    }

    private function buatAttachment(): TaskAttachment
    {
        Storage::fake('local');
        $task = TaskReport::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'kabid_sikep_user_id' => $this->kabidId,
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'T',
            'durasi_diajukan_menit' => 120, 'status' => 'diajukan',
        ]);
        Storage::disk('local')->put('bukti/x/s.jpg', 'konten-rahasia');

        return TaskAttachment::create([
            'task_report_id' => $task->id, 'tipe' => 'foto_selfie',
            'path' => 'bukti/x/s.jpg', 'nama_asli' => 's.jpg', 'mime' => 'image/jpeg', 'ukuran' => 14,
        ]);
    }

    public function test_pemilik_boleh_mengakses(): void
    {
        $att = $this->buatAttachment();
        $this->mockRoles([$this->katimkerId => ['katimker']]);
        $this->actingAs(User::factory()->create(['sikep_user_id' => $this->katimkerId]));

        $this->get(route('bukti.show', $att->id))->assertOk();
    }

    public function test_kabid_penilai_boleh_mengakses(): void
    {
        $att = $this->buatAttachment();
        $this->mockRoles([$this->kabidId => ['kabid']]);
        $this->actingAs(User::factory()->create(['sikep_user_id' => $this->kabidId]));

        $this->get(route('bukti.show', $att->id))->assertOk();
    }

    public function test_admin_boleh_mengakses(): void
    {
        $att = $this->buatAttachment();
        $this->mockRoles([111 => ['admin']]);
        $this->actingAs(User::factory()->create([
            'sikep_user_id' => 111, 'is_admin' => true, 'two_factor_confirmed_at' => now(),
        ]));

        $this->get(route('bukti.show', $att->id))->assertOk();
    }

    public function test_pihak_lain_ditolak(): void
    {
        $att = $this->buatAttachment();
        $this->mockRoles([888 => ['katimker']]); // bukan pemilik, bukan kabid, bukan admin
        $this->actingAs(User::factory()->create(['sikep_user_id' => 888]));

        $this->get(route('bukti.show', $att->id))->assertForbidden();
    }

    public function test_kabid_lain_ditolak(): void
    {
        $att = $this->buatAttachment();
        $this->mockRoles([999 => ['kabid']]); // kabid, tapi bukan penilai tugas ini
        $this->actingAs(User::factory()->create(['sikep_user_id' => 999]));

        $this->get(route('bukti.show', $att->id))->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
