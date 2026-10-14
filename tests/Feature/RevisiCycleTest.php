<?php

namespace Tests\Feature;

use App\Models\Activity;
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
 * Siklus revisi: revisi → edit (otomatis masuk penilai lagi), ditolak final, dan immutability status disetujui.
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

    public function test_siklus_revisi_edit_masuk_penilai_lagi_dengan_jejak_audit(): void
    {
        Storage::fake('local');
        ApproverMapping::create(['katimker_sikep_user_id' => $this->katimkerId, 'kabid_sikep_user_id' => $this->kabidId]);
        $kegiatan = Activity::create(['nama' => 'Judul diperbaiki']);

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

        // 1) Kabid meminta revisi dengan keterangan.
        $this->actingAs($kabid)
            ->post(route('kabid.penilaian.revisi', $task->id), ['keterangan' => 'Kegiatan kurang jelas'])
            ->assertRedirect();
        $this->assertSame('revisi', $task->refresh()->status);

        // 2) Katimker memperbaiki: menyimpan = otomatis kembali ke proses penilaian.
        $this->actingAs($katimker)
            ->post(route('katimker.tugas.update', $task->id), [
                'tanggal' => '2026-10-06', 'jenis' => 'utama', 'kegiatan_id' => $kegiatan->id,
                'durasi_jam' => 2, 'durasi_menit' => 0,
            ])
            ->assertRedirect(route('katimker.tugas.index'));
        $task->refresh();
        $this->assertSame('Judul diperbaiki', $task->judul);
        $this->assertSame('diajukan', $task->status);
        $this->assertNull($task->catatan_penilai); // jejak revisi dibersihkan dari tugas

        // 3) Kabid menolak: final.
        $this->actingAs($kabid)
            ->post(route('kabid.penilaian.reject', $task->id), ['keterangan' => 'Tetap tidak sesuai'])
            ->assertRedirect();
        $this->assertSame('ditolak', $task->refresh()->status);

        // 4) Katimker tidak dapat memperbaiki lagi.
        $this->actingAs($katimker)
            ->post(route('katimker.tugas.update', $task->id), [
                'tanggal' => '2026-10-06', 'jenis' => 'utama', 'kegiatan_id' => $kegiatan->id,
                'durasi_jam' => 1, 'durasi_menit' => 0,
            ])
            ->assertForbidden();

        // 5) Riwayat audit append-only menyimpan revisi → submit → reject berurutan.
        $aksi = AuditLog::query()->where('subject_id', $task->id)->orderBy('id')->pluck('aksi')->all();
        $this->assertSame(['task.revisi', 'task.submit', 'task.reject'], array_values(array_intersect($aksi, ['task.revisi', 'task.submit', 'task.reject'])));
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
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'kegiatan_id' => Activity::create(['nama' => 'Ubah'])->id,
            'durasi_jam' => 1, 'durasi_menit' => 0,
        ])->assertForbidden();
        $this->delete(route('katimker.tugas.destroy', $task->id))->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
