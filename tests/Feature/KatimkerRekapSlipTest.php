<?php

namespace Tests\Feature;

use App\Models\IncentiveConfig;
use App\Models\IncentivePlafonOverride;
use App\Models\IncentiveResult;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\IncentiveStatementService;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** Rekap Katimker: perkiraan/penetapan insentif per komponen dan slip PDF (Final / Estimasi). */
class KatimkerRekapSlipTest extends TestCase
{
    use RefreshDatabase;

    private int $katimkerId = 500;

    private function actingAsKatimker(): User
    {
        $user = User::factory()->katimker()->create(['sikep_user_id' => $this->katimkerId, 'nama' => 'dr. Uji Katimker']);
        $this->mock(RoleResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
            'roles' => ['katimker'], 'is_katimker' => true, 'is_kabid' => false, 'is_admin' => false,
        ]));
        $this->actingAs($user);

        return $user;
    }

    private function siapkanEstimasi(): void
    {
        $cfg = IncentiveConfig::global();
        $cfg->forceFill(['nominal_dasar' => 1_000_000, 'bobot_kehadiran' => 40, 'bobot_utama' => 50, 'bobot_tambahan' => 10])->save();
        IncentivePlafonOverride::create([
            'katimker_sikep_user_id' => $this->katimkerId, 'tahun' => 2026, 'bulan' => 10, 'nominal' => 1_000_000, 'di_set_oleh' => 1,
        ]);

        TaskReport::create([
            'katimker_sikep_user_id' => $this->katimkerId, 'kabid_sikep_user_id' => 700, 'tanggal' => '2026-10-06',
            'jenis' => 'utama', 'judul' => 'Visite', 'durasi_diajukan_menit' => 120, 'durasi_disetujui_menit' => 120, 'status' => 'disetujui',
        ]);
    }

    private function buatFinal(): void
    {
        IncentiveResult::create([
            'katimker_sikep_user_id' => $this->katimkerId, 'tahun' => 2026, 'bulan' => 10,
            'persen_kehadiran' => 100, 'persen_utama' => 50, 'persen_tambahan' => 0, 'persen_total' => 65,
            'nominal_plafon' => 1_000_000, 'nominal_insentif' => 650_000,
            'utama_menit' => 600, 'tambahan_menit' => 0, 'target_utama_menit' => 1200, 'target_tambahan_menit' => 600,
            'bobot_kehadiran' => 40, 'bobot_utama' => 50, 'bobot_tambahan' => 10,
            'difinalkan_oleh' => 1, 'difinalkan_pada' => now(),
        ]);
    }

    public function test_rekap_menampilkan_estimasi_bila_belum_final(): void
    {
        $this->actingAsKatimker();
        $this->siapkanEstimasi();

        $this->get(route('katimker.rekap.index', ['tahun' => 2026, 'bulan' => 10]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Rekap/Katimker')
                ->where('insentif.status', 'estimasi')
                ->where('insentif.plafon', 1_000_000)
                ->has('insentif.komponen', 3)
                ->has('tugas', 1)
                ->where('ringkas_status.disetujui.jumlah', 1)
                ->where('ringkas_status.disetujui.menit', 120));
    }

    public function test_rekap_memakai_snapshot_bila_sudah_final(): void
    {
        $this->actingAsKatimker();
        $this->siapkanEstimasi();
        $this->buatFinal();

        $this->get(route('katimker.rekap.index', ['tahun' => 2026, 'bulan' => 10]))
            ->assertInertia(fn ($p) => $p
                ->where('insentif.status', 'final')
                ->where('insentif.nominal', 650_000)
                ->whereNot('insentif.difinalkan_pada', null));
    }

    public function test_jumlah_rincian_komponen_sama_dengan_total(): void
    {
        $user = $this->actingAsKatimker();
        $this->siapkanEstimasi();
        $this->buatFinal();

        $d = app(IncentiveStatementService::class)->untuk($user, 2026, 10);

        $this->assertSame($d['plafon'], array_sum(array_column($d['komponen'], 'maksimal')));
        $this->assertSame($d['nominal'], array_sum(array_column($d['komponen'], 'diperoleh')));
        $this->assertSame([400_000, 250_000, 0], array_column($d['komponen'], 'diperoleh'));
    }

    public function test_slip_pdf_estimasi_dapat_diunduh(): void
    {
        $this->actingAsKatimker();
        $this->siapkanEstimasi();

        $response = $this->get(route('katimker.rekap.slip', ['tahun' => 2026, 'bulan' => 10]));

        $response->assertOk();
        $response->assertDownload('slip-insentif-2026-10-estimasi.pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_slip_pdf_final_tanpa_akhiran_estimasi(): void
    {
        $this->actingAsKatimker();
        $this->buatFinal();

        $this->get(route('katimker.rekap.slip', ['tahun' => 2026, 'bulan' => 10]))
            ->assertOk()
            ->assertDownload('slip-insentif-2026-10.pdf');
    }

    public function test_slip_hanya_milik_sendiri_dan_butuh_peran_katimker(): void
    {
        $this->actingAsKatimker();
        // Hasil final milik orang lain tidak boleh bocor ke slip kita.
        IncentiveResult::create([
            'katimker_sikep_user_id' => 999, 'tahun' => 2026, 'bulan' => 10,
            'persen_kehadiran' => 100, 'persen_utama' => 100, 'persen_tambahan' => 100, 'persen_total' => 100,
            'nominal_plafon' => 5_000_000, 'nominal_insentif' => 5_000_000,
            'utama_menit' => 0, 'tambahan_menit' => 0, 'bobot_kehadiran' => 40, 'bobot_utama' => 50, 'bobot_tambahan' => 10,
        ]);

        $this->get(route('katimker.rekap.index', ['tahun' => 2026, 'bulan' => 10]))
            ->assertInertia(fn ($p) => $p->where('insentif.status', 'estimasi')->where('insentif.nominal', 0));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
