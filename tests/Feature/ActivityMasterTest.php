<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ActivityMasterTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $isAdmin = $role === 'admin';
        $user = User::factory()->create([
            'role' => $role,
            'is_admin' => $isAdmin,
            'aktif' => true,
            'two_factor_confirmed_at' => $isAdmin ? now() : null,
        ]);

        $this->mock(RoleResolver::class, function ($m) use ($role) {
            $m->shouldReceive('resolve')->andReturn([
                'roles' => [$role],
                'is_katimker' => $role === 'katimker',
                'is_kabid' => $role === 'kabid',
                'is_admin' => $role === 'admin',
            ]);
        });

        $this->actingAs($user);

        return $user;
    }

    public function test_admin_dapat_melihat_menambah_mengubah_dan_menonaktifkan(): void
    {
        $this->actingAsRole('admin');

        $this->get(route('admin.kegiatan.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Kegiatan'));

        $this->post(route('admin.kegiatan.store'), ['nama' => 'Visite pasien'])->assertSessionHasNoErrors();
        $k = Activity::firstOrFail();
        $this->assertTrue($k->aktif);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'activity.create']);

        $this->post(route('admin.kegiatan.update', $k->id), ['nama' => 'Visite harian', 'aktif' => false])
            ->assertSessionHasNoErrors();
        $k->refresh();
        $this->assertSame('Visite harian', $k->nama);
        $this->assertFalse($k->aktif);
    }

    public function test_nama_wajib_dan_unik(): void
    {
        $this->actingAsRole('admin');
        Activity::create(['nama' => 'Rapat']);

        $this->post(route('admin.kegiatan.store'), ['nama' => ''])->assertSessionHasErrors('nama');
        $this->post(route('admin.kegiatan.store'), ['nama' => 'Rapat'])->assertSessionHasErrors('nama');
        $this->assertSame(1, Activity::count());
    }

    public function test_kegiatan_terpakai_tidak_dapat_dihapus(): void
    {
        $this->actingAsRole('admin');
        $k = Activity::create(['nama' => 'Terpakai']);
        TaskReport::create([
            'katimker_sikep_user_id' => 500, 'tanggal' => '2026-10-06', 'jenis' => 'utama',
            'kegiatan_id' => $k->id, 'judul' => 'Terpakai', 'durasi_diajukan_menit' => 60, 'status' => 'diajukan',
        ]);

        $this->delete(route('admin.kegiatan.destroy', $k->id))->assertSessionHas('error');
        $this->assertDatabaseHas('activities', ['id' => $k->id]);

        $kosong = Activity::create(['nama' => 'Kosong']);
        $this->delete(route('admin.kegiatan.destroy', $kosong->id))->assertSessionHas('success');
        $this->assertDatabaseMissing('activities', ['id' => $kosong->id]);
    }

    public function test_non_admin_ditolak(): void
    {
        $this->actingAsRole('katimker');

        $this->get(route('admin.kegiatan.index'))->assertForbidden();
        $this->post(route('admin.kegiatan.store'), ['nama' => 'X'])->assertForbidden();
    }

    public function test_form_tugas_hanya_menawarkan_kegiatan_aktif(): void
    {
        Activity::create(['nama' => 'Aktif']);
        Activity::create(['nama' => 'Nonaktif', 'aktif' => false]);
        $this->actingAsRole('katimker');

        $this->get(route('katimker.tugas.create'))
            ->assertInertia(fn ($page) => $page->has('kegiatan', 1)->where('kegiatan.0.nama', 'Aktif'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
