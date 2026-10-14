<?php

namespace Tests\Feature;

use App\Models\TaskReport;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class KabidReviewTest extends TestCase
{
    use RefreshDatabase;

    private int $kabidId = 700;

    private int $katimkerId = 500;

    private function actingAsKabid(?int $sikepUserId = null): User
    {
        $user = User::factory()->create(['sikep_user_id' => $sikepUserId ?? $this->kabidId]);

        $this->mock(RoleResolver::class, function ($m) {
            $m->shouldReceive('resolve')->andReturn([
                'roles' => ['kabid'],
                'is_katimker' => false, 'is_kabid' => true, 'is_admin' => false,
            ]);
        });

        $this->actingAs($user);

        return $user;
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

    public function test_approve_penuh_mengubah_status_disetujui(): void
    {
        $this->actingAsKabid();
        $task = $this->tugasDiajukan();

        $this->post(route('kabid.penilaian.approve', $task->id), [
            'durasi_jam' => 2, 'durasi_menit' => 0,
        ])->assertRedirect(route('kabid.penilaian.index'));

        $task->refresh();
        $this->assertSame('disetujui', $task->status);
        $this->assertSame(120, $task->durasi_disetujui_menit);
        $this->assertNull($task->alasan_pengurangan);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'task.approve', 'subject_id' => $task->id]);
    }

    public function test_approve_sebagian_tanpa_alasan_ditolak(): void
    {
        $this->actingAsKabid();
        $task = $this->tugasDiajukan();

        $this->from(route('kabid.penilaian.show', $task->id))
            ->post(route('kabid.penilaian.approve', $task->id), [
                'durasi_jam' => 1, 'durasi_menit' => 0, // < 120, tanpa alasan
            ])
            ->assertSessionHas('error');

        $this->assertSame('diajukan', $task->refresh()->status);
    }

    public function test_approve_sebagian_dengan_alasan_berhasil(): void
    {
        $this->actingAsKabid();
        $task = $this->tugasDiajukan();

        $this->post(route('kabid.penilaian.approve', $task->id), [
            'durasi_jam' => 1, 'durasi_menit' => 0, 'keterangan' => 'Sebagian di luar jam efektif',
        ])->assertRedirect();

        $task->refresh();
        $this->assertSame('disetujui', $task->status);
        $this->assertSame(60, $task->durasi_disetujui_menit);
        $this->assertSame('Sebagian di luar jam efektif', $task->alasan_pengurangan);
    }

    public function test_reject_tanpa_catatan_ditolak_validasi(): void
    {
        $this->actingAsKabid();
        $task = $this->tugasDiajukan();

        $this->from(route('kabid.penilaian.show', $task->id))
            ->post(route('kabid.penilaian.reject', $task->id), ['keterangan' => ''])
            ->assertSessionHasErrors('keterangan');

        $this->assertSame('diajukan', $task->refresh()->status);
    }

    public function test_reject_dengan_catatan_mengubah_status(): void
    {
        $this->actingAsKabid();
        $task = $this->tugasDiajukan();

        $this->post(route('kabid.penilaian.reject', $task->id), ['keterangan' => 'Bukti kurang jelas'])
            ->assertRedirect(route('kabid.penilaian.index'));

        $task->refresh();
        $this->assertSame('ditolak', $task->status);
        $this->assertSame('Bukti kurang jelas', $task->catatan_penilai);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'task.reject', 'subject_id' => $task->id]);
    }

    public function test_revisi_mengembalikan_tugas_dan_dapat_diajukan_kembali(): void
    {
        $this->actingAsKabid();
        $task = $this->tugasDiajukan();

        $this->post(route('kabid.penilaian.revisi', $task->id), ['keterangan' => 'Lengkapi bukti'])
            ->assertRedirect(route('kabid.penilaian.index'));

        $task->refresh();
        $this->assertSame('revisi', $task->status);
        $this->assertSame('Lengkapi bukti', $task->catatan_penilai);
        $this->assertTrue($task->isEditable());
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'task.revisi', 'subject_id' => $task->id]);
    }

    public function test_revisi_tanpa_keterangan_ditolak_validasi(): void
    {
        $this->actingAsKabid();
        $task = $this->tugasDiajukan();

        $this->from(route('kabid.penilaian.show', $task->id))
            ->post(route('kabid.penilaian.revisi', $task->id), ['keterangan' => ''])
            ->assertSessionHasErrors('keterangan');

        $this->assertSame('diajukan', $task->refresh()->status);
    }

    public function test_tolak_bersifat_final_tidak_dapat_diedit(): void
    {
        $this->actingAsKabid();
        $task = $this->tugasDiajukan();

        $this->post(route('kabid.penilaian.reject', $task->id), ['keterangan' => 'Tidak sesuai'])->assertRedirect();

        $this->assertSame('ditolak', $task->refresh()->status);
        $this->assertFalse($task->isEditable());
    }

    public function test_penilaian_periode_terkunci_ditolak(): void
    {
        $this->actingAsKabid();
        // Sep: cut-off 10 Okt sudah lewat pada 13 Okt.
        $task = $this->tugasDiajukan(['tanggal' => '2026-09-20']);

        $this->post(route('kabid.penilaian.approve', $task->id), ['durasi_jam' => 2, 'durasi_menit' => 0])
            ->assertForbidden();
        $this->post(route('kabid.penilaian.revisi', $task->id), ['keterangan' => 'x'])->assertForbidden();
        $this->post(route('kabid.penilaian.reject', $task->id), ['keterangan' => 'x'])->assertForbidden();

        $this->assertSame('diajukan', $task->refresh()->status);
    }

    public function test_detail_menyertakan_peringatan_bukti(): void
    {
        $this->actingAsKabid();
        $task = $this->tugasDiajukan(); // tanpa lampiran

        $this->get(route('kabid.penilaian.show', $task->id))
            ->assertInertia(fn ($page) => $page
                ->where('tugas.dapat_dinilai', true)
                ->has('tugas.peringatan', 2));
    }

    public function test_kabid_lain_tidak_dapat_menilai(): void
    {
        $this->actingAsKabid(999); // bukan kabid tugas ini
        $task = $this->tugasDiajukan();

        $this->post(route('kabid.penilaian.approve', $task->id), [
            'durasi_jam' => 2, 'durasi_menit' => 0,
        ])->assertForbidden();
    }

    public function test_tugas_disetujui_tidak_dapat_dinilai_lagi(): void
    {
        $this->actingAsKabid();
        $task = $this->tugasDiajukan(['status' => 'disetujui', 'durasi_disetujui_menit' => 120]);

        // Policy review menolak karena status bukan diajukan.
        $this->post(route('kabid.penilaian.reject', $task->id), ['keterangan' => 'coba'])
            ->assertForbidden();
    }

    public function test_approve_melebihi_diajukan_ditolak(): void
    {
        $this->actingAsKabid();
        $task = $this->tugasDiajukan(); // 120 menit

        $this->from(route('kabid.penilaian.show', $task->id))
            ->post(route('kabid.penilaian.approve', $task->id), [
                'durasi_jam' => 3, 'durasi_menit' => 0, // 180 > 120
            ])
            ->assertSessionHas('error');

        $this->assertSame('diajukan', $task->refresh()->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
