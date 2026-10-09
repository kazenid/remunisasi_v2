<?php

namespace Tests\Feature;

use App\Models\IncentiveConfig;
use App\Models\IncentivePlafonOverride;
use App\Models\IncentiveResult;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IncentiveCalculationControllerTest extends TestCase
{
    use RefreshDatabase;

    private int $tahun = 2026;

    private int $bulan = 10;

    private int $katimkerId = 500;

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

    private function fakeKehadiran(): void
    {
        $this->mock(AttendanceService::class, function ($m) {
            $m->shouldReceive('nilai')->andReturn(['tersedia' => true, 'persen_kehadiran' => 100.0]);
        });
    }

    private function buatKatimker(): void
    {
        User::factory()->create([
            'sikep_user_id' => $this->katimkerId, 'no_absen' => 77,
            'role' => User::ROLE_KATIMKER, 'aktif' => true, 'nama' => 'Pegawai A',
        ]);
    }

    public function test_admin_membuka_hitung_insentif(): void
    {
        $this->actingAsAdmin();
        $this->fakeKehadiran();
        $this->buatKatimker();

        $this->get(route('admin.insentif.hitung.index', ['tahun' => $this->tahun, 'bulan' => $this->bulan]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Admin/HitungInsentif')
                ->has('baris', 1)
                ->where('tahun', $this->tahun)
                ->where('bulan', $this->bulan));
    }

    public function test_non_admin_ditolak(): void
    {
        $user = User::factory()->create(['sikep_user_id' => 900]);
        $this->mock(RoleResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
            'roles' => ['kabid'], 'is_katimker' => false, 'is_kabid' => true, 'is_admin' => false,
        ]));
        $this->actingAs($user);

        $this->get(route('admin.insentif.hitung.index'))->assertForbidden();
    }

    public function test_simpan_plafon_override(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.insentif.hitung.plafon'), [
            'katimker_sikep_user_id' => $this->katimkerId,
            'tahun' => $this->tahun, 'bulan' => $this->bulan,
            'nominal' => 3000000,
        ])->assertRedirect();

        $this->assertDatabaseHas('incentive_plafon_overrides', [
            'katimker_sikep_user_id' => $this->katimkerId,
            'tahun' => $this->tahun, 'bulan' => $this->bulan,
            'nominal' => 3000000, 'di_set_oleh' => 111,
        ]);
    }

    public function test_plafon_menolak_nominal_negatif(): void
    {
        $this->actingAsAdmin();

        $this->from(route('admin.insentif.hitung.index'))
            ->post(route('admin.insentif.hitung.plafon'), [
                'katimker_sikep_user_id' => $this->katimkerId,
                'tahun' => $this->tahun, 'bulan' => $this->bulan,
                'nominal' => -5,
            ])->assertSessionHasErrors('nominal');

        $this->assertSame(0, IncentivePlafonOverride::count());
    }

    public function test_finalkan_menyimpan_hasil(): void
    {
        $this->actingAsAdmin();
        $this->fakeKehadiran();
        $this->buatKatimker();
        IncentiveConfig::global()->update([
            'bobot_kehadiran' => 100, 'bobot_utama' => 0, 'bobot_tambahan' => 0,
            'nominal_dasar' => 1000000,
        ]);

        $this->post(route('admin.insentif.hitung.finalkan'), [
            'tahun' => $this->tahun, 'bulan' => $this->bulan,
        ])->assertRedirect();

        $this->assertSame(1, IncentiveResult::count());
        $this->assertDatabaseHas('incentive_results', [
            'katimker_sikep_user_id' => $this->katimkerId,
            'nominal_insentif' => 1000000, 'difinalkan_oleh' => 111,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
