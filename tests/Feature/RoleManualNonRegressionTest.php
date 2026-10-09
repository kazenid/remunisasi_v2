<?php

namespace Tests\Feature;

use App\Models\ApproverMapping;
use App\Models\TaskAttachment;
use App\Models\TaskReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Non-regresi alur tugas TANPA mock RoleResolver: membuktikan jalur
 * active-role → role: → EnsureRole → roles() bekerja pada sumber role lokal.
 */
class RoleManualNonRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $katimkerId = 500;

    private int $kabidId = 700;

    public function test_katimker_nyata_dapat_membuat_dan_mengajukan_tugas(): void
    {
        $katimker = User::factory()->katimker()->create(['sikep_user_id' => $this->katimkerId]);
        $this->actingAs($katimker);

        // Buat tugas (draf) + lampiran selfie + pemetaan.
        $task = TaskReport::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'tanggal' => '2026-10-06',
            'jenis' => 'utama',
            'judul' => 'Tugas non-regresi',
            'durasi_diajukan_menit' => 120,
            'status' => 'draf',
        ]);
        TaskAttachment::create([
            'task_report_id' => $task->id,
            'tipe' => 'foto_selfie',
            'path' => 'bukti/x/selfie.jpg',
            'nama_asli' => 'selfie.jpg',
            'mime' => 'image/jpeg',
            'ukuran' => 1000,
        ]);
        ApproverMapping::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'kabid_sikep_user_id' => $this->kabidId,
        ]);

        $this->post(route('katimker.tugas.submit', $task->id))
            ->assertRedirect(route('katimker.tugas.index'));

        $this->assertSame('diajukan', $task->refresh()->status);
    }

    public function test_kabid_nyata_dapat_menyetujui_tugas_binaannya(): void
    {
        $kabid = User::factory()->kabid()->create(['sikep_user_id' => $this->kabidId]);
        $this->actingAs($kabid);

        $task = TaskReport::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'kabid_sikep_user_id' => $this->kabidId,
            'tanggal' => '2026-10-06',
            'jenis' => 'utama',
            'judul' => 'Tugas untuk dinilai',
            'durasi_diajukan_menit' => 120,
            'status' => 'diajukan',
            'diajukan_pada' => now(),
        ]);

        $this->post(route('kabid.penilaian.approve', $task->id), [
            'durasi_jam' => 2, 'durasi_menit' => 0,
        ])->assertRedirect(route('kabid.penilaian.index'));

        $this->assertSame('disetujui', $task->refresh()->status);
    }
}
