<?php

namespace Tests\Feature;

use App\Models\ApproverMapping;
use App\Models\TaskAttachment;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class KatimkerSubmitTest extends TestCase
{
    use RefreshDatabase;

    private int $sikepId = 500;

    private int $kabidId = 700;

    private function actingAsKatimker(): User
    {
        $user = User::factory()->create(['sikep_user_id' => $this->sikepId]);

        $this->mock(RoleResolver::class, function ($m) {
            $m->shouldReceive('resolve')->andReturn([
                'roles' => ['katimker'],
                'is_katimker' => true, 'is_kabid' => false, 'is_admin' => false,
            ]);
        });

        $this->actingAs($user);

        return $user;
    }

    private function buatTugas(array $overrides = []): TaskReport
    {
        return TaskReport::create(array_merge([
            'katimker_sikep_user_id' => $this->sikepId,
            'tanggal' => '2026-10-06',
            'jenis' => 'utama',
            'judul' => 'Tugas uji',
            'durasi_diajukan_menit' => 120,
            'status' => 'draf',
        ], $overrides));
    }

    private function tambahSelfie(TaskReport $task): void
    {
        TaskAttachment::create([
            'task_report_id' => $task->id,
            'tipe' => 'foto_selfie',
            'path' => 'bukti/x/selfie.jpg',
            'nama_asli' => 'selfie.jpg',
            'mime' => 'image/jpeg',
            'ukuran' => 1000,
        ]);
    }

    private function petakan(): void
    {
        ApproverMapping::create([
            'katimker_sikep_user_id' => $this->sikepId,
            'kabid_sikep_user_id' => $this->kabidId,
        ]);
    }

    public function test_submit_valid_mengubah_status_dan_snapshot_kabid(): void
    {
        $this->actingAsKatimker();
        $task = $this->buatTugas();
        $this->tambahSelfie($task);
        $this->petakan();

        $this->post(route('katimker.tugas.submit', $task->id))
            ->assertRedirect(route('katimker.tugas.index'));

        $task->refresh();
        $this->assertSame('diajukan', $task->status);
        $this->assertSame($this->kabidId, $task->kabid_sikep_user_id);
        $this->assertNotNull($task->diajukan_pada);

        $this->assertDatabaseHas('audit_logs', [
            'aksi' => 'task.submit',
            'subject_id' => $task->id,
        ]);
    }

    public function test_submit_tanpa_selfie_ditolak(): void
    {
        $this->actingAsKatimker();
        $task = $this->buatTugas();
        $this->petakan(); // ada mapping, tapi tidak ada selfie

        $this->from(route('katimker.tugas.index'))
            ->post(route('katimker.tugas.submit', $task->id))
            ->assertRedirect(route('katimker.tugas.index'))
            ->assertSessionHas('error')
            // Bug 2 (sisi server): pengajuan gagal TIDAK boleh menitipkan flash success,
            // sehingga frontend tidak dapat memunculkan toast sukses palsu.
            ->assertSessionMissing('success');

        $this->assertSame('draf', $task->refresh()->status);
    }

    public function test_submit_tanpa_mapping_diblokir(): void
    {
        $this->actingAsKatimker();
        $task = $this->buatTugas();
        $this->tambahSelfie($task); // ada selfie, tapi tidak dipetakan

        $this->from(route('katimker.tugas.index'))
            ->post(route('katimker.tugas.submit', $task->id))
            ->assertSessionHas('error');

        $this->assertSame('draf', $task->refresh()->status);
    }

    public function test_tugas_diajukan_tidak_dapat_diajukan_lagi(): void
    {
        $this->actingAsKatimker();
        $task = $this->buatTugas(['status' => 'diajukan']);
        $this->tambahSelfie($task);
        $this->petakan();

        // Policy update menolak karena status bukan editable.
        $this->post(route('katimker.tugas.submit', $task->id))->assertForbidden();
    }

    public function test_bukti_gambar_memenuhi_syarat_selfie_saat_submit(): void
    {
        Storage::fake('local');
        $this->actingAsKatimker();
        $this->petakan();

        // Simpan tugas dengan gambar lewat field campuran bukti[].
        $this->post(route('katimker.tugas.store'), [
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'Dari bukti gambar',
            'durasi_jam' => 1, 'durasi_menit' => 0,
            'bukti' => [UploadedFile::fake()->image('foto.jpg')],
        ])->assertRedirect(route('katimker.tugas.index'));

        $task = TaskReport::first();
        $this->assertSame(1, $task->attachments()->where('tipe', 'foto_selfie')->count());

        $this->post(route('katimker.tugas.submit', $task->id))
            ->assertRedirect(route('katimker.tugas.index'));

        $this->assertSame('diajukan', $task->refresh()->status);
    }

    public function test_resubmit_dari_ditolak_berhasil(): void
    {
        $this->actingAsKatimker();
        $task = $this->buatTugas(['status' => 'ditolak', 'catatan_penilai' => 'Bukti kurang jelas']);
        $this->tambahSelfie($task);
        $this->petakan();

        $this->post(route('katimker.tugas.submit', $task->id))->assertRedirect();

        $task->refresh();
        $this->assertSame('diajukan', $task->status);
        $this->assertNull($task->catatan_penilai); // jejak penilaian lama dibersihkan
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
