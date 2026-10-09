<?php

namespace Tests\Feature;

use App\Models\TaskReport;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ReclassifyTest extends TestCase
{
    use RefreshDatabase;

    private int $kabidId = 700;

    private int $katimkerId = 500;

    private function actingAsKabid(?int $id = null): void
    {
        $user = User::factory()->create(['sikep_user_id' => $id ?? $this->kabidId]);
        $this->mock(RoleResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
            'roles' => ['kabid'], 'is_katimker' => false, 'is_kabid' => true, 'is_admin' => false,
        ]));
        $this->actingAs($user);
    }

    private function tugas(array $o = []): TaskReport
    {
        return TaskReport::create(array_merge([
            'katimker_sikep_user_id' => $this->katimkerId,
            'kabid_sikep_user_id' => $this->kabidId,
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'T',
            'durasi_diajukan_menit' => 120, 'status' => 'diajukan', 'diajukan_pada' => now(),
        ], $o));
    }

    public function test_ubah_jenis_terpisah_dengan_alasan(): void
    {
        $this->actingAsKabid();
        $t = $this->tugas();

        $this->post(route('kabid.penilaian.jenis', $t->id), ['jenis' => 'tambahan', 'alasan' => 'Di luar tupoksi'])
            ->assertRedirect();

        $this->assertSame('tambahan', $t->refresh()->jenis);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'task.reclassify', 'subject_id' => $t->id]);
    }

    public function test_ubah_jenis_tanpa_alasan_ditolak(): void
    {
        $this->actingAsKabid();
        $t = $this->tugas();

        $this->from(route('kabid.penilaian.show', $t->id))
            ->post(route('kabid.penilaian.jenis', $t->id), ['jenis' => 'tambahan', 'alasan' => ''])
            ->assertSessionHasErrors('alasan');

        $this->assertSame('utama', $t->refresh()->jenis);
    }

    public function test_ubah_jenis_saat_approve(): void
    {
        $this->actingAsKabid();
        $t = $this->tugas();

        $this->post(route('kabid.penilaian.approve', $t->id), [
            'durasi_jam' => 2, 'durasi_menit' => 0,
            'jenis' => 'tambahan', 'alasan_jenis' => 'Lembur di luar jam',
        ])->assertRedirect();

        $t->refresh();
        $this->assertSame('tambahan', $t->jenis);
        $this->assertSame('disetujui', $t->status);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'task.reclassify', 'subject_id' => $t->id]);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'task.approve', 'subject_id' => $t->id]);
    }

    public function test_kabid_lain_tidak_bisa_ubah_jenis(): void
    {
        $this->actingAsKabid(999);
        $t = $this->tugas();

        $this->post(route('kabid.penilaian.jenis', $t->id), ['jenis' => 'tambahan', 'alasan' => 'x'])
            ->assertForbidden();
    }

    public function test_tidak_bisa_ubah_jenis_tugas_disetujui(): void
    {
        $this->actingAsKabid();
        $t = $this->tugas(['status' => 'disetujui', 'durasi_disetujui_menit' => 120]);

        // Policy review menolak (status bukan diajukan).
        $this->post(route('kabid.penilaian.jenis', $t->id), ['jenis' => 'tambahan', 'alasan' => 'x'])
            ->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
