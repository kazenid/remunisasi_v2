<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ApproverMapping;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class AssignmentTest extends TestCase
{
    use RefreshDatabase;

    private int $kabidId = 700;

    private int $katimkerId = 500;

    private function mockRoleByUser(): void
    {
        $kabidId = $this->kabidId;
        $katimkerId = $this->katimkerId;
        $this->mock(RoleResolver::class, function ($m) use ($kabidId, $katimkerId) {
            $m->shouldReceive('resolve')->andReturnUsing(fn (int $id) => [
                'roles' => array_values(array_filter([
                    $id === $katimkerId ? 'katimker' : null,
                    $id === $kabidId ? 'kabid' : null,
                ])),
                'is_katimker' => $id === $katimkerId,
                'is_kabid' => $id === $kabidId,
                'is_admin' => false,
            ]);
        });
    }

    private function petakan(): void
    {
        ApproverMapping::create(['katimker_sikep_user_id' => $this->katimkerId, 'kabid_sikep_user_id' => $this->kabidId]);
    }

    public function test_kabid_pembina_membuat_penugasan_tambahan(): void
    {
        $this->mockRoleByUser();
        $this->petakan();
        $kabid = User::factory()->create(['sikep_user_id' => $this->kabidId]);

        $kegiatan = Activity::create(['nama' => 'Pasang jaringan']);

        $this->actingAs($kabid)->post(route('kabid.penugasan.store'), [
            'katimker_sikep_user_id' => $this->katimkerId,
            'tanggal' => '2026-10-06',
            'kegiatan_id' => $kegiatan->id,
        ])->assertRedirect(route('kabid.penilaian.index'));

        $t = TaskReport::first();
        $this->assertNotNull($t);
        $this->assertSame('tambahan', $t->jenis);
        $this->assertTrue((bool) $t->jenis_terkunci);
        $this->assertSame('menunggu_bukti', $t->status);
        $this->assertSame('Pasang jaringan', $t->judul);
        $this->assertSame($this->katimkerId, $t->katimker_sikep_user_id);
        $this->assertSame($this->kabidId, $t->kabid_sikep_user_id);
        $this->assertSame($this->kabidId, $t->dibuat_oleh_sikep_user_id);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'task.assign', 'subject_id' => $t->id]);
    }

    public function test_kabid_bukan_pembina_ditolak(): void
    {
        $this->mockRoleByUser();
        // tidak dipetakan
        $kabid = User::factory()->create(['sikep_user_id' => $this->kabidId]);

        $this->actingAs($kabid)->from(route('kabid.penugasan.create'))->post(route('kabid.penugasan.store'), [
            'katimker_sikep_user_id' => $this->katimkerId,
            'tanggal' => '2026-10-06', 'kegiatan_id' => Activity::create(['nama' => 'X'])->id,
        ])->assertSessionHasErrors('katimker_sikep_user_id');

        $this->assertSame(0, TaskReport::count());
    }

    public function test_katimker_isi_bukti_penugasan_otomatis_masuk_penilai(): void
    {
        Storage::fake('local');
        $this->mockRoleByUser();
        $this->petakan();

        $assignment = TaskReport::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'kabid_sikep_user_id' => $this->kabidId,
            'dibuat_oleh_sikep_user_id' => $this->kabidId,
            'jenis_terkunci' => true,
            'tanggal' => '2026-10-06', 'jenis' => 'tambahan', 'judul' => 'Tugas dari kabid',
            'durasi_diajukan_menit' => 0, 'status' => 'menunggu_bukti',
        ]);

        $katimker = User::factory()->create(['sikep_user_id' => $this->katimkerId]);
        $kegiatan = Activity::create(['nama' => 'Tugas dari kabid']);

        // Katimker lengkapi bukti + durasi (jenis dikirim 'utama' tapi harus diabaikan karena terkunci).
        $this->actingAs($katimker)->post(route('katimker.tugas.update', $assignment->id), [
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'kegiatan_id' => $kegiatan->id,
            'durasi_jam' => 1, 'durasi_menit' => 30,
            'foto_selfie' => [UploadedFile::fake()->image('s.jpg')],
        ])->assertRedirect();

        $assignment->refresh();
        $this->assertSame('tambahan', $assignment->jenis); // tetap tambahan (terkunci)
        $this->assertSame(90, $assignment->durasi_diajukan_menit);

        // Menyimpan = otomatis masuk proses penilaian.
        $this->assertSame('diajukan', $assignment->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
