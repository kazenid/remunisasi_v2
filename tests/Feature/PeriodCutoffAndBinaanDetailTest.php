<?php

namespace Tests\Feature;

use App\Models\ApproverMapping;
use App\Models\IncentiveResult;
use App\Models\Period;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/** Cut-off periode (jatuh di bulan berikutnya), kartu periode dashboard, dan detail tugas binaan. */
class PeriodCutoffAndBinaanDetailTest extends TestCase
{
    use RefreshDatabase;

    private function mockRoles(array $roles): void
    {
        $this->mock(RoleResolver::class, function ($m) use ($roles) {
            $m->shouldReceive('resolve')->andReturn([
                'roles' => $roles,
                'is_katimker' => in_array('katimker', $roles, true),
                'is_kabid' => in_array('kabid', $roles, true),
                'is_admin' => in_array('admin', $roles, true),
            ]);
        });
    }

    public function test_cutoff_dan_target_bawaan_jatuh_di_bulan_berikutnya(): void
    {
        $p = Period::forDate(Carbon::parse('2026-09-15'));

        $this->assertSame('2026-10-10', $p->cut_off->toDateString());
        $this->assertSame('2026-10-15', $p->target_cair->toDateString());

        $des = Period::forDate(Carbon::parse('2026-12-02'));
        $this->assertSame('2027-01-10', $des->cut_off->toDateString());
    }

    public function test_periode_terkunci_mulai_tanggal_cutoff(): void
    {
        $this->assertFalse(Period::lockedFor(2026, 9, Carbon::parse('2026-10-09')));
        $this->assertTrue(Period::lockedFor(2026, 9, Carbon::parse('2026-10-10')));
        $this->assertTrue(Period::lockedFor(2026, 9, Carbon::parse('2026-10-20')));
        $this->assertFalse(Period::lockedFor(2026, 10, Carbon::parse('2026-10-20'))); // periode berjalan tetap terbuka
    }

    public function test_admin_dapat_mengunci_periode_sebelum_cutoff(): void
    {
        Period::create(['tahun' => 2026, 'bulan' => 10, 'status' => 'terkunci']);

        $this->assertTrue(Period::lockedFor(2026, 10, Carbon::parse('2026-10-13')));
    }

    public function test_dashboard_menampilkan_periode_lalu_selama_belum_cair(): void
    {
        $user = User::factory()->katimker()->create(['sikep_user_id' => 500]);
        $this->mockRoles(['katimker']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('periode.bulan', 10)
                ->where('periode.terkunci', false)
                ->where('periodeLalu.bulan', 9)
                ->where('periodeLalu.cut_off', '2026-10-10')
                ->where('periodeLalu.terkunci', true)
                ->where('periodeLalu.target_realisasi', '2026-10-15'));
    }

    public function test_dashboard_menyembunyikan_periode_lalu_setelah_cair(): void
    {
        $user = User::factory()->katimker()->create(['sikep_user_id' => 500]);
        $this->mockRoles(['katimker']);
        IncentiveResult::create([
            'katimker_sikep_user_id' => 500, 'tahun' => 2026, 'bulan' => 9,
            'persen_kehadiran' => 100, 'persen_utama' => 100, 'persen_tambahan' => 100, 'persen_total' => 100,
            'nominal_plafon' => 1000000, 'nominal_insentif' => 1000000,
            'utama_menit' => 0, 'tambahan_menit' => 0,
            'bobot_kehadiran' => 40, 'bobot_utama' => 40, 'bobot_tambahan' => 20,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('periodeLalu', null));
    }

    public function test_dashboard_katimker_memuat_jumlah_dan_menit_per_status(): void
    {
        $user = User::factory()->katimker()->create(['sikep_user_id' => 500]);
        $this->mockRoles(['katimker']);
        foreach ([['diajukan', 90, null], ['diajukan', 30, null], ['disetujui', 120, 100]] as [$st, $dj, $ds]) {
            TaskReport::create([
                'katimker_sikep_user_id' => 500, 'kabid_sikep_user_id' => 700, 'tanggal' => '2026-10-06',
                'jenis' => 'utama', 'judul' => 'T', 'durasi_diajukan_menit' => $dj, 'durasi_disetujui_menit' => $ds, 'status' => $st,
            ]);
        }

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('katimker.ringkas.diajukan.jumlah', 2)
                ->where('katimker.ringkas.diajukan.menit', 120)
                ->where('katimker.ringkas.disetujui.jumlah', 1)
                ->where('katimker.ringkas.disetujui.menit', 100));
    }

    // ─── Detail tugas binaan (Kabid) ─────────────────────────────────────

    private function binaan(): User
    {
        $kabid = User::factory()->kabid()->create(['sikep_user_id' => 700]);
        $this->mockRoles(['kabid']);
        ApproverMapping::create(['katimker_sikep_user_id' => 500, 'kabid_sikep_user_id' => 700]);

        return $kabid;
    }

    public function test_kabid_melihat_semua_tugas_katimker_binaan(): void
    {
        $kabid = $this->binaan();
        foreach (['diajukan', 'revisi', 'disetujui', 'ditolak'] as $st) {
            TaskReport::create([
                'katimker_sikep_user_id' => 500, 'kabid_sikep_user_id' => 700, 'tanggal' => '2026-10-06',
                'jenis' => 'utama', 'judul' => "T $st", 'durasi_diajukan_menit' => 60, 'status' => $st,
            ]);
        }
        // Tugas Katimker lain tidak ikut.
        TaskReport::create([
            'katimker_sikep_user_id' => 501, 'kabid_sikep_user_id' => 700, 'tanggal' => '2026-10-06',
            'jenis' => 'utama', 'judul' => 'Lain', 'durasi_diajukan_menit' => 60, 'status' => 'diajukan',
        ]);

        $this->actingAs($kabid)->get(route('kabid.rekap.tugas', 500))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Kabid/BinaanTugas')
                ->has('tugas', 4)
                ->where('katimker.sikep_user_id', 500));
    }

    public function test_kabid_tidak_dapat_melihat_katimker_bukan_binaannya(): void
    {
        $kabid = $this->binaan();

        $this->actingAs($kabid)->get(route('kabid.rekap.tugas', 999))->assertForbidden();
    }

    public function test_detail_tugas_non_diajukan_dapat_dibuka_tanpa_aksi_penilaian(): void
    {
        $kabid = $this->binaan();
        $t = TaskReport::create([
            'katimker_sikep_user_id' => 500, 'kabid_sikep_user_id' => 700, 'tanggal' => '2026-10-06',
            'jenis' => 'utama', 'judul' => 'Final', 'durasi_diajukan_menit' => 60, 'durasi_disetujui_menit' => 60, 'status' => 'disetujui',
        ]);

        $this->actingAs($kabid)->get(route('kabid.penilaian.show', $t->id))
            ->assertInertia(fn ($page) => $page->where('tugas.dapat_dinilai', false)->where('tugas.status', 'disetujui'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
