<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\SetsUpSikepConnection;
use Tests\TestCase;

/**
 * Jalur tarik pegawai SIKEP + tetapkan role. Memakai koneksi sikep SQLite
 * in-memory agar SikepUser dapat ditemukan. RoleResolver NYATA (kolom lokal).
 */
class TarikPegawaiTest extends TestCase
{
    use RefreshDatabase, SetsUpSikepConnection;

    private int $adminId = 777;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSikepSqlite();
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->admin()->create([
            'sikep_user_id' => $this->adminId,
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_tarik_pegawai_membuat_user_aktif_berperan(): void
    {
        $this->actingAsAdmin();
        $this->buatPegawaiSikep(820, 'KATIMKER BARU', 'uji820');

        $this->post(route('admin.pengguna.tarik'), ['sikep_user_id' => 820, 'role' => 'katimker'])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'sikep_user_id' => 820,
            'role' => 'katimker',
            'aktif' => true,
            'is_admin' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'pengguna.tarik']);
    }

    public function test_tarik_role_admin_menyelaraskan_is_admin(): void
    {
        $this->actingAsAdmin();
        $this->buatPegawaiSikep(821, 'ADMIN BARU', 'uji821');

        $this->post(route('admin.pengguna.tarik'), ['sikep_user_id' => 821, 'role' => 'admin'])
            ->assertRedirect();

        $user = User::where('sikep_user_id', 821)->first();
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->aktif);
    }

    public function test_tarik_pegawai_tidak_ditemukan_ditolak(): void
    {
        $this->actingAsAdmin();

        $this->from(route('admin.pengguna.index'))
            ->post(route('admin.pengguna.tarik'), ['sikep_user_id' => 99999, 'role' => 'katimker'])
            ->assertSessionHasErrors('sikep_user_id');

        $this->assertDatabaseMissing('users', ['sikep_user_id' => 99999]);
    }

    public function test_tarik_role_di_luar_domain_ditolak(): void
    {
        $this->actingAsAdmin();
        $this->buatPegawaiSikep(822, 'PEGAWAI', 'uji822');

        $this->from(route('admin.pengguna.index'))
            ->post(route('admin.pengguna.tarik'), ['sikep_user_id' => 822, 'role' => 'superuser'])
            ->assertSessionHasErrors('role');
    }
}
