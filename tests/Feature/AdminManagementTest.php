<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private int $adminId = 111;

    private function actingAsAdmin(): User
    {
        // Admin dengan 2FA sudah terkonfirmasi (lolos EnsureTwoFactor).
        $user = User::factory()->admin()->create([
            'sikep_user_id' => $this->adminId,
            'two_factor_confirmed_at' => now(),
        ]);

        $this->mock(RoleResolver::class, function ($m) {
            $m->shouldReceive('resolve')->andReturnUsing(fn (int $id) => [
                'roles' => $id === $this->adminId ? ['admin'] : [],
                'is_katimker' => false, 'is_kabid' => false, 'is_admin' => $id === $this->adminId,
            ]);
        });

        $this->actingAs($user);

        return $user;
    }

    public function test_admin_membuka_manajemen_pengguna(): void
    {
        $this->actingAsAdmin();

        $this->get(route('admin.pengguna.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Pengguna')->has('users'));
    }

    public function test_admin_membuka_manajemen_pengguna_memuat_props_baru(): void
    {
        $this->actingAsAdmin();

        $this->get(route('admin.pengguna.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Admin/Pengguna')
                ->has('users.0.role')
                ->has('users.0.aktif')
                ->has('roleOptions', 3));
    }

    public function test_admin_menjadikan_pengguna_lain_admin(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->katimker()->create(['sikep_user_id' => 222]);

        $this->post(route('admin.pengguna.role', $target), ['role' => 'admin'])
            ->assertRedirect();

        $this->assertTrue($target->refresh()->is_admin);
        $this->assertSame('admin', $target->refresh()->role);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'pengguna.role']);
    }

    public function test_admin_tidak_bisa_menurunkan_peran_diri_sendiri(): void
    {
        $self = $this->actingAsAdmin();

        $this->from(route('admin.pengguna.index'))
            ->post(route('admin.pengguna.role', $self), ['role' => 'katimker'])
            ->assertSessionHasErrors('role');

        $this->assertSame('admin', $self->refresh()->role);
    }

    public function test_admin_tidak_bisa_menonaktifkan_akun_sendiri(): void
    {
        $self = $this->actingAsAdmin();

        $this->from(route('admin.pengguna.index'))
            ->post(route('admin.pengguna.aktif', $self), ['aktif' => false])
            ->assertSessionHasErrors('aktif');

        $this->assertTrue($self->refresh()->aktif);
    }

    public function test_admin_dapat_menonaktifkan_pengguna_lain(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->katimker()->create(['sikep_user_id' => 333]);

        $this->post(route('admin.pengguna.aktif', $target), ['aktif' => false])
            ->assertRedirect();

        $this->assertFalse($target->refresh()->aktif);
        // Record tidak dihapus (retensi audit).
        $this->assertDatabaseHas('users', ['sikep_user_id' => 333]);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'pengguna.aktif']);
    }

    public function test_tarik_pegawai_yang_sudah_ditarik_ditolak(): void
    {
        $this->actingAsAdmin();

        // Guard "sudah ditarik": record lokal sudah ada → tarik() menolak.
        $sudahAda = User::factory()->katimker()->create(['sikep_user_id' => 444]);

        $this->from(route('admin.pengguna.index'))
            ->post(route('admin.pengguna.tarik'), ['sikep_user_id' => 444, 'role' => 'kabid'])
            ->assertSessionHasErrors('sikep_user_id');

        // Role tidak berubah karena ditolak sebagai sudah ditarik.
        $this->assertSame('katimker', $sudahAda->refresh()->role);
    }

    public function test_admin_menyiapkan_dan_mengubah_periode(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.periode.store'), ['tahun' => 2026, 'bulan' => 10])->assertRedirect();
        $periode = Period::where('tahun', 2026)->where('bulan', 10)->first();
        $this->assertNotNull($periode);

        $this->post(route('admin.periode.update', $periode->id), [
            'cut_off' => '2026-10-10', 'target_cair' => '2026-10-15', 'status' => 'terkunci',
        ])->assertRedirect();

        $this->assertSame('terkunci', $periode->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'period.update']);
    }

    public function test_halaman_integrasi_menampilkan_status(): void
    {
        $this->actingAsAdmin();

        $this->get(route('admin.integrasi.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Integrasi')->has('integrasi.status'));
    }

    public function test_non_admin_ditolak_dari_manajemen_pengguna(): void
    {
        $user = User::factory()->create(['sikep_user_id' => 500]);
        $this->mock(RoleResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
            'roles' => ['katimker'], 'is_katimker' => true, 'is_kabid' => false, 'is_admin' => false,
        ]));
        $this->actingAs($user);

        $this->get(route('admin.pengguna.index'))->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
