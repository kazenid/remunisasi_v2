<?php

namespace Tests\Feature;

use App\Models\ApproverMapping;
use App\Models\User;
use App\Services\ApproverMappingService;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ApproverMappingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(array $roles): User
    {
        $isAdmin = in_array('admin', $roles, true);
        $user = User::factory()->create([
            'role' => $roles[0] ?? null,
            'is_admin' => $isAdmin,
            'aktif' => true,
            // Admin wajib 2FA terkonfirmasi agar lolos EnsureTwoFactor.
            'two_factor_confirmed_at' => $isAdmin ? now() : null,
        ]);

        // Resolusi peran untuk middleware/HandleInertiaRequests di-stub.
        $this->mock(RoleResolver::class, function ($m) use ($roles) {
            $m->shouldReceive('resolve')->andReturn([
                'roles' => $roles,
                'is_katimker' => in_array('katimker', $roles, true),
                'is_kabid' => in_array('kabid', $roles, true),
                'is_admin' => in_array('admin', $roles, true),
            ]);
        });

        $this->actingAs($user);

        return $user;
    }

    public function test_admin_dapat_membuka_halaman_pemetaan(): void
    {
        $this->actingAsRole(['admin']);
        User::factory()->katimker()->create(['sikep_user_id' => 10]);
        User::factory()->kabid()->create(['sikep_user_id' => 20]);

        $this->get(route('admin.pemetaan.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ApproverMapping')
                ->has('katimker', 1)
                ->has('kabidOptions', 1));
    }

    public function test_non_admin_ditolak(): void
    {
        $this->actingAsRole(['katimker']);

        $this->get(route('admin.pemetaan.index'))->assertForbidden();
    }

    public function test_admin_dapat_menyimpan_pemetaan(): void
    {
        $this->actingAsRole(['admin']);
        User::factory()->katimker()->create(['sikep_user_id' => 10]);
        User::factory()->kabid()->create(['sikep_user_id' => 20]);

        $this->post(route('admin.pemetaan.store'), [
            'katimker_sikep_user_id' => 10,
            'kabid_sikep_user_id' => 20,
        ])->assertRedirect();

        $this->assertTrue(session()->has('success'));
        $this->assertDatabaseHas('approver_mappings', [
            'katimker_sikep_user_id' => 10,
            'kabid_sikep_user_id' => 20,
        ]);
    }

    public function test_target_bukan_kabid_aktif_error_pada_field_kabid(): void
    {
        $this->actingAsRole(['admin']);
        User::factory()->katimker()->create(['sikep_user_id' => 10]);
        // 99 bukan kabid aktif.

        $this->from(route('admin.pemetaan.index'))
            ->post(route('admin.pemetaan.store'), [
                'katimker_sikep_user_id' => 10,
                'kabid_sikep_user_id' => 99,
            ])
            ->assertRedirect(route('admin.pemetaan.index'))
            ->assertSessionHasErrors(['kabid_sikep_user_id' => 'Target penilai bukan Kabid aktif.']);
    }

    public function test_sumber_bukan_katimker_aktif_error_pada_field_katimker(): void
    {
        $this->actingAsRole(['admin']);
        User::factory()->kabid()->create(['sikep_user_id' => 20]);
        // 15 bukan katimker aktif.

        $this->from(route('admin.pemetaan.index'))
            ->post(route('admin.pemetaan.store'), [
                'katimker_sikep_user_id' => 15,
                'kabid_sikep_user_id' => 20,
            ])
            ->assertSessionHasErrors(['katimker_sikep_user_id' => 'Pengguna bukan Katimker aktif.']);
    }

    public function test_non_admin_tidak_dapat_menyimpan(): void
    {
        $this->actingAsRole(['kabid']);

        $this->post(route('admin.pemetaan.store'), [
            'katimker_sikep_user_id' => 10,
            'kabid_sikep_user_id' => 20,
        ])->assertForbidden();
    }

    public function test_list_katimker_menandai_kabid_tidak_valid(): void
    {
        $katimker = User::factory()->katimker()->create(['sikep_user_id' => 10]);
        $kabid = User::factory()->kabid()->create(['sikep_user_id' => 20]);
        ApproverMapping::create([
            'katimker_sikep_user_id' => 10,
            'kabid_sikep_user_id' => 20,
            'aktif' => true,
        ]);

        $service = app(ApproverMappingService::class);

        $row = $service->listKatimker()->firstWhere('sikep_user_id', 10);
        $this->assertTrue($row['kabid_valid']);

        // Kabid dinonaktifkan → pemetaan tidak lagi valid, nama historis tetap tampil.
        $kabid->update(['aktif' => false]);
        $row = $service->listKatimker()->firstWhere('sikep_user_id', 10);
        $this->assertFalse($row['kabid_valid']);
        $this->assertSame($kabid->nama, $row['kabid_nama']);
    }

    public function test_assign_menolak_target_bukan_kabid_aktif(): void
    {
        User::factory()->katimker()->create(['sikep_user_id' => 10]);
        User::factory()->kabid()->nonaktif()->create(['sikep_user_id' => 20]);

        $service = app(ApproverMappingService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target penilai bukan Kabid aktif.');
        $service->assign(10, 20);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
