<?php

namespace Tests\Feature;

use App\Models\IncentiveConfig;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IncentiveConfigTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create([
            'sikep_user_id' => 111, 'is_admin' => true, 'two_factor_confirmed_at' => now(),
        ]);
        $this->mock(RoleResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
            'roles' => ['admin'], 'is_katimker' => false, 'is_kabid' => false, 'is_admin' => true,
        ]));
        $this->actingAs($user);

        return $user;
    }

    public function test_admin_membuka_konfigurasi(): void
    {
        $this->actingAsAdmin();

        $this->get(route('admin.insentif.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/KonfigurasiInsentif')->has('config'));
    }

    public function test_simpan_konfigurasi_valid(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.insentif.update'), [
            'target_utama_jam' => 150, 'target_utama_menit_sisa' => 0,
            'target_tambahan_jam' => 10, 'target_tambahan_menit_sisa' => 30,
            'bobot_kehadiran' => 40, 'bobot_utama' => 50, 'bobot_tambahan' => 10,
            'nominal_dasar' => 10000000,
        ])->assertRedirect();

        $cfg = IncentiveConfig::global();
        $this->assertSame(9000, $cfg->target_utama_menit);     // 150 jam
        $this->assertSame(630, $cfg->target_tambahan_menit);   // 10 jam 30 menit
        $this->assertSame(10000000, $cfg->nominal_dasar);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'incentive_config.update']);
    }

    public function test_bobot_tidak_100_ditolak(): void
    {
        $this->actingAsAdmin();

        $this->from(route('admin.insentif.index'))
            ->post(route('admin.insentif.update'), [
                'bobot_kehadiran' => 40, 'bobot_utama' => 40, 'bobot_tambahan' => 10, // = 90
            ])
            ->assertSessionHasErrors('bobot_kehadiran');
    }

    public function test_non_admin_ditolak(): void
    {
        $user = User::factory()->create(['sikep_user_id' => 500]);
        $this->mock(RoleResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
            'roles' => ['kabid'], 'is_katimker' => false, 'is_kabid' => true, 'is_admin' => false,
        ]));
        $this->actingAs($user);

        $this->get(route('admin.insentif.index'))->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
