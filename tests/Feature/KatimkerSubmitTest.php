<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ApproverMapping;
use App\Models\TaskAttachment;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Alur baru: tersimpan = langsung masuk penilai (proses penilaian), tanpa
 * langkah "ajukan" terpisah. Bukti tidak diwajibkan (penilai diberi peringatan).
 */
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

    private function petakan(): void
    {
        ApproverMapping::create([
            'katimker_sikep_user_id' => $this->sikepId,
            'kabid_sikep_user_id' => $this->kabidId,
        ]);
    }

    private function payload(array $override = []): array
    {
        $kegiatan = Activity::query()->first() ?? Activity::create(['nama' => 'Visite pasien']);

        return array_merge([
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'kegiatan_id' => $kegiatan->id,
            'durasi_jam' => 2, 'durasi_menit' => 0,
        ], $override);
    }

    private function buatTugas(array $overrides = []): TaskReport
    {
        $kegiatan = Activity::query()->first() ?? Activity::create(['nama' => 'Visite pasien']);

        return TaskReport::create(array_merge([
            'katimker_sikep_user_id' => $this->sikepId,
            'kabid_sikep_user_id' => $this->kabidId,
            'tanggal' => '2026-10-06',
            'jenis' => 'utama',
            'kegiatan_id' => $kegiatan->id,
            'judul' => $kegiatan->nama,
            'durasi_diajukan_menit' => 120,
            'status' => 'diajukan',
            'diajukan_pada' => now(),
        ], $overrides));
    }

    public function test_simpan_langsung_masuk_penilai_dengan_snapshot_kabid(): void
    {
        $this->actingAsKatimker();
        $this->petakan();

        $this->post(route('katimker.tugas.store'), $this->payload())
            ->assertRedirect(route('katimker.tugas.index'));

        $task = TaskReport::firstOrFail();
        $this->assertSame('diajukan', $task->status);
        $this->assertSame($this->kabidId, $task->kabid_sikep_user_id);
        $this->assertNotNull($task->diajukan_pada);
        $this->assertSame('Visite pasien', $task->judul); // snapshot nama kegiatan
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'task.submit', 'subject_id' => $task->id]);
    }

    public function test_simpan_tanpa_selfie_tetap_masuk_penilai_dengan_peringatan(): void
    {
        $this->actingAsKatimker();
        $this->petakan();

        $this->post(route('katimker.tugas.store'), $this->payload())->assertRedirect();

        $task = TaskReport::firstOrFail();
        $this->assertSame('diajukan', $task->status);
        $this->assertNotEmpty($task->peringatanBukti());
    }

    public function test_simpan_tanpa_mapping_diblokir(): void
    {
        $this->actingAsKatimker();

        $this->from(route('katimker.tugas.create'))
            ->post(route('katimker.tugas.store'), $this->payload())
            ->assertSessionHas('error')
            ->assertSessionMissing('success');

        $this->assertSame(0, TaskReport::count());
    }

    public function test_kegiatan_wajib_dan_harus_aktif(): void
    {
        $this->actingAsKatimker();
        $this->petakan();
        $nonaktif = Activity::create(['nama' => 'Lama', 'aktif' => false]);

        $this->post(route('katimker.tugas.store'), $this->payload(['kegiatan_id' => null]))
            ->assertSessionHasErrors('kegiatan_id');
        $this->post(route('katimker.tugas.store'), $this->payload(['kegiatan_id' => $nonaktif->id]))
            ->assertSessionHasErrors('kegiatan_id');

        $this->assertSame(0, TaskReport::count());
    }

    public function test_bukti_gambar_dihitung_sebagai_selfie(): void
    {
        Storage::fake('local');
        $this->actingAsKatimker();
        $this->petakan();

        $this->post(route('katimker.tugas.store'), $this->payload([
            'bukti' => [UploadedFile::fake()->image('foto.jpg')],
        ]))->assertRedirect(route('katimker.tugas.index'));

        $task = TaskReport::firstOrFail();
        $this->assertSame(1, $task->attachments()->where('tipe', 'foto_selfie')->count());
        $this->assertSame([], $task->peringatanBukti());
    }

    public function test_edit_saat_proses_penilaian_tetap_diajukan(): void
    {
        $this->actingAsKatimker();
        $this->petakan();
        $task = $this->buatTugas();

        $this->post(route('katimker.tugas.update', $task->id), $this->payload(['durasi_jam' => 3]))
            ->assertRedirect(route('katimker.tugas.index'));

        $task->refresh();
        $this->assertSame('diajukan', $task->status);
        $this->assertSame(180, $task->durasi_diajukan_menit);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'task.update', 'subject_id' => $task->id]);
    }

    public function test_simpan_ulang_dari_revisi_kembali_ke_proses_penilaian(): void
    {
        $this->actingAsKatimker();
        $this->petakan();
        $task = $this->buatTugas(['status' => 'revisi', 'catatan_penilai' => 'Bukti kurang jelas']);

        $this->post(route('katimker.tugas.update', $task->id), $this->payload())->assertRedirect();

        $task->refresh();
        $this->assertSame('diajukan', $task->status);
        $this->assertNull($task->catatan_penilai); // jejak penilaian lama dibersihkan
    }

    public function test_tugas_ditolak_final_tidak_dapat_diubah(): void
    {
        $this->actingAsKatimker();
        $this->petakan();
        $task = $this->buatTugas(['status' => 'ditolak', 'catatan_penilai' => 'Tidak sesuai']);

        $this->post(route('katimker.tugas.update', $task->id), $this->payload())->assertForbidden();
        $this->assertSame('ditolak', $task->refresh()->status);
    }

    public function test_input_periode_lalu_ditutup_pada_tanggal_cutoff(): void
    {
        $this->actingAsKatimker();
        $this->petakan();

        // Hari ini 13 Okt: cut-off Sep = 10 Okt sudah lewat → Sep ditutup, Okt masih terbuka.
        $this->from(route('katimker.tugas.create'))
            ->post(route('katimker.tugas.store'), $this->payload(['tanggal' => '2026-09-20']))
            ->assertSessionHas('error');
        $this->assertSame(0, TaskReport::count());

        $this->post(route('katimker.tugas.store'), $this->payload(['tanggal' => '2026-10-06']))->assertRedirect();
        $this->assertSame(1, TaskReport::count());
    }

    public function test_periode_lalu_masih_terbuka_sebelum_cutoff(): void
    {
        $this->actingAsKatimker();
        $this->petakan();
        Carbon::setTestNow('2026-10-09 10:00:00'); // 1 hari sebelum cut-off

        $this->post(route('katimker.tugas.store'), $this->payload(['tanggal' => '2026-09-20']))->assertRedirect();
        $this->assertSame(1, TaskReport::count());
    }

    public function test_edit_tugas_periode_terkunci_ditolak(): void
    {
        $this->actingAsKatimker();
        $this->petakan();
        $task = $this->buatTugas(['tanggal' => '2026-09-20']);

        $this->post(route('katimker.tugas.update', $task->id), $this->payload(['tanggal' => '2026-10-06']))->assertForbidden();
        $this->delete(route('katimker.tugas.destroy', $task->id))->assertForbidden();
    }

    public function test_attachment_helper_tetap_bekerja(): void
    {
        $this->actingAsKatimker();
        $task = $this->buatTugas();
        TaskAttachment::create([
            'task_report_id' => $task->id, 'tipe' => 'foto_selfie', 'path' => 'bukti/x/s.jpg',
            'nama_asli' => 's.jpg', 'mime' => 'image/jpeg', 'ukuran' => 10,
        ]);

        $this->assertTrue($task->hasSelfie());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
