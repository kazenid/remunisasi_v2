<?php

namespace Tests\Feature;

use App\Models\ApproverMapping;
use App\Models\IncentiveConfig;
use App\Models\IndividualTarget;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use App\Services\TargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IndividualTargetTest extends TestCase
{
    use RefreshDatabase;

    private int $kabidId = 700;

    private int $katimkerId = 500;

    public function test_resolver_individu_mengalahkan_global(): void
    {
        $g = IncentiveConfig::global();
        $g->update(['target_utama_menit' => 9000, 'target_tambahan_menit' => 600]);

        // Tanpa override → global.
        $r = app(TargetResolver::class)->efektif($this->katimkerId, 2026, 10);
        $this->assertSame('global', $r['sumber']);
        $this->assertSame(9000, $r['utama_menit']);

        // Override individu umum.
        IndividualTarget::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'tahun' => null, 'bulan' => null,
            'target_utama_menit' => 12000, 'target_tambahan_menit' => 300,
        ]);
        $r2 = app(TargetResolver::class)->efektif($this->katimkerId, 2026, 10);
        $this->assertSame('individu_umum', $r2['sumber']);
        $this->assertSame(12000, $r2['utama_menit']);

        // Override periode spesifik mengalahkan umum.
        IndividualTarget::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'tahun' => 2026, 'bulan' => 10,
            'target_utama_menit' => 15000, 'target_tambahan_menit' => 0,
        ]);
        $r3 = app(TargetResolver::class)->efektif($this->katimkerId, 2026, 10);
        $this->assertSame('individu_periodik', $r3['sumber']);
        $this->assertSame(15000, $r3['utama_menit']);
    }

    public function test_kabid_pembina_set_target_binaan(): void
    {
        ApproverMapping::create(['katimker_sikep_user_id' => $this->katimkerId, 'kabid_sikep_user_id' => $this->kabidId]);
        $kabid = User::factory()->create(['sikep_user_id' => $this->kabidId]);
        $this->mock(RoleResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
            'roles' => ['kabid'], 'is_katimker' => false, 'is_kabid' => true, 'is_admin' => false,
        ]));
        $this->actingAs($kabid);

        $this->post(route('kabid.target.store'), [
            'katimker_sikep_user_id' => $this->katimkerId,
            'target_utama_jam' => 150, 'target_tambahan_jam' => 10,
        ])->assertRedirect();

        $t = IndividualTarget::where('katimker_sikep_user_id', $this->katimkerId)->first();
        $this->assertSame(9000, $t->target_utama_menit);
        $this->assertSame(600, $t->target_tambahan_menit);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'target.update']);
    }

    public function test_kabid_bukan_pembina_tidak_bisa_set_target(): void
    {
        // tidak dipetakan
        $kabid = User::factory()->create(['sikep_user_id' => $this->kabidId]);
        $this->mock(RoleResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
            'roles' => ['kabid'], 'is_katimker' => false, 'is_kabid' => true, 'is_admin' => false,
        ]));
        $this->actingAs($kabid);

        $this->from(route('kabid.rekap.index'))->post(route('kabid.target.store'), [
            'katimker_sikep_user_id' => $this->katimkerId,
            'target_utama_jam' => 150,
        ])->assertSessionHasErrors('katimker_sikep_user_id');

        $this->assertSame(0, IndividualTarget::count());
    }

    public function test_admin_set_target_siapa_pun(): void
    {
        $admin = User::factory()->create([
            'sikep_user_id' => 111, 'is_admin' => true, 'two_factor_confirmed_at' => now(),
        ]);
        $this->mock(RoleResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
            'roles' => ['admin'], 'is_katimker' => false, 'is_kabid' => false, 'is_admin' => true,
        ]));
        $this->actingAs($admin);

        $this->post(route('admin.target.store'), [
            'katimker_sikep_user_id' => $this->katimkerId,
            'target_utama_jam' => 120,
        ])->assertRedirect();

        $this->assertSame(7200, IndividualTarget::first()->target_utama_menit);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
