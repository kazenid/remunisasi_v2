<?php

namespace Tests\Feature;

use App\Models\ApproverMapping;
use App\Models\AuditLog;
use App\Models\TaskAttachment;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Siklus revisi: ditolak → edit → ajukan ulang, dan immutability status disetujui.
 */
class RevisiCycleTest extends TestCase
{
    use RefreshDatabase;

    private int $katimkerId = 500;

    private int $kabidId = 700;

    /**
     * Mock RoleResolver yang menentukan peran berdasarkan sikep_user_id,
     * sehingga Katimker dan Kabid dapat berganti dalam satu test tanpa reset aplikasi.
     */
    private function mockRoleByUser(): void
    {
        $katimkerId = $this->katimkerId;
        $kabidId = $this->kabidId;

        $this->mock(RoleResolver::class, function ($m) use ($katimkerId, $kabidId) {
            $m->shouldReceive('resolve')->andReturnUsing(function (int $sikepUserId) use ($katimkerId, $kabidId) {
                $isKatimker = $sikepUserId === $katimkerId;
                $isKabid = $sikepUserId === $kabidId;

                return [
                    'roles' => array_values(array_filter([
                        $isKatimker ? 'katimker' : null,
                        $isKabid ? 'kabid' : null,
                    ])),
                    'is_katimker' => $isKatimker,
                    'is_kabid' => $isKabid,
                    'is_admin' => false,
                ];
            });
        });
    }

    private function mockRole(array $roles): void
    {
        $this->mock(RoleResolver::class, function ($m) use ($roles) {
            $m->shouldReceive('resolve')->andReturn([
                'roles' => $roles,
                'is_katimker' => in_array('katimker', $roles, true),
                'is_kabid' => in_array('kabid', $roles, true),
                'is_admin' => in_array('admin', $roles, true),
            ]);
        });
    }

    private function selfie(TaskReport $t): void
    {
        TaskAttachment::create([
            'task_report_id' => $t->id, 'tipe' => 'foto_selfie',
            'path' => 'bukti/x/s.jpg', 'nama_asli' => 's.jpg', 'mime' => 'image/jpeg', 'ukuran' => 10,
        ]);
    }

    public function test_siklus_ditolak_edit_resubmit_dengan_jejak_audit(): void
    {
        Storage::fake('local');
        ApproverMapping::create(['katimker_sikep_user_id' => $this->katimkerId, 'kabid_sikep_user_id' => $this->kabidId]);

        $task = TaskReport::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'kabid_sikep_user_id' => $this->kabidId,
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'Awal',
            'durasi_diajukan_menit' => 120, 'status' => 'diajukan', 'diajukan_pada' => now(),
        ]);
        $this->selfie($task);

        $this->mockRoleByUser();
        $kabid = User::factory()->create(['sikep_user_id' => $this->kabidId]);
        $katimker = User::factory()->create(['sikep_user_id' => $this->katimkerId]);

        // 1) Kabid menolak dengan catatan.
        $this->actingAs($kabid)
            ->post(route('kabid.penilaian.reject', $task->id), ['catatan' => 'Judul kurang jelas'])
            ->assertRedirect();
        $this->assertSame('ditolak', $task->refresh()->status);

        // 2) Katimker mengedit tugas yang ditolak.
        $this->actingAs($katimker)
            ->post(route('katimker.tugas.update', $task->id), [
                'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'Judul diperbaiki',
                'durasi_jam' => 2, 'durasi_menit' => 0,
            ])
            ->assertRedirect(route('katimker.tugas.index'));
        $this->assertSame('Judul diperbaiki', $task->refresh()->judul);
        $this->assertSame('ditolak', $task->status); // masih ditolak sampai diajukan ulang

        // 3) Katimker mengajukan ulang.
        $this->post(route('katimker.tugas.submit', $task->id))->assertRedirect();
        $task->refresh();
        $this->assertSame('diajukan', $task->status);
        $this->assertNull($task->catatan_penilai); // jejak penolakan dibersihkan dari tugas

        // 4) Riwayat audit append-only tetap menyimpan reject lalu submit.
        $aksi = AuditLog::query()->where('subject_id', $task->id)->orderBy('id')->pluck('aksi')->all();
        $this->assertContains('task.reject', $aksi);
        $this->assertContains('task.submit', $aksi);
        $this->assertLessThan(
            array_search('task.submit', $aksi, true),
            array_search('task.reject', $aksi, true),
            'task.reject harus tercatat sebelum task.submit',
        );
    }

    public function test_tugas_disetujui_immutable_bagi_katimker(): void
    {
        $task = TaskReport::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'kabid_sikep_user_id' => $this->kabidId,
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'Final',
            'durasi_diajukan_menit' => 120, 'durasi_disetujui_menit' => 120, 'status' => 'disetujui',
        ]);

        $katimker = User::factory()->create(['sikep_user_id' => $this->katimkerId]);
        $this->mockRole(['katimker']);
        $this->actingAs($katimker);

        // Tidak bisa diedit, diajukan ulang, maupun dihapus.
        $this->get(route('katimker.tugas.edit', $task->id))->assertOk(); // boleh lihat
        $this->post(route('katimker.tugas.update', $task->id), [
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'Ubah', 'durasi_jam' => 1, 'durasi_menit' => 0,
        ])->assertForbidden();
        $this->post(route('katimker.tugas.submit', $task->id))->assertForbidden();
        $this->delete(route('katimker.tugas.destroy', $task->id))->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
