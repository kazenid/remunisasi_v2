<?php

namespace Tests\Feature;

use App\Models\IncentiveConfig;
use App\Models\IncentivePlafonOverride;
use App\Models\IncentiveResult;
use App\Models\IndividualTarget;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\IncentiveCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IncentiveCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private int $tahun = 2026;

    private int $bulan = 10;

    private int $katimkerId = 500;

    private int $noAbsen = 77;

    /**
     * Seed satu Katimker lokal aktif.
     */
    private function buatKatimker(?int $id = null, ?int $noAbsen = null): User
    {
        return User::factory()->create([
            'sikep_user_id' => $id ?? $this->katimkerId,
            'no_absen' => $noAbsen ?? $this->noAbsen,
            'role' => User::ROLE_KATIMKER,
            'aktif' => true,
            'nama' => 'Pegawai '.($id ?? $this->katimkerId),
        ]);
    }

    /**
     * Palsukan AttendanceService via container agar tak perlu DB SIKEP.
     */
    private function fakeKehadiran(bool $tersedia, float $persen): void
    {
        $this->mock(AttendanceService::class, function ($m) use ($tersedia, $persen) {
            $m->shouldReceive('nilai')->andReturn([
                'tersedia' => $tersedia,
                'persen_kehadiran' => $persen,
            ]);
        });
    }

    private function tugasDisetujui(string $jenis, int $menit): void
    {
        TaskReport::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'tanggal' => sprintf('%04d-%02d-06', $this->tahun, $this->bulan),
            'jenis' => $jenis,
            'judul' => 'T',
            'durasi_diajukan_menit' => $menit,
            'durasi_disetujui_menit' => $menit,
            'status' => TaskReport::STATUS_DISETUJUI,
        ]);
    }

    private function hitungBaris(): array
    {
        return app(IncentiveCalculatorService::class)
            ->hitung($this->tahun, $this->bulan)
            ->firstWhere('katimker_sikep_user_id', $this->katimkerId);
    }

    public function test_formula_berbobot_total_persen(): void
    {
        $this->buatKatimker();
        $this->fakeKehadiran(true, 80.0);
        // bobot default 40/50/10; target 100 & 100 menit; capaian 60/50 menit.
        IncentiveConfig::global()->update([
            'target_utama_menit' => 100, 'target_tambahan_menit' => 100,
            'nominal_dasar' => 1000000,
        ]);
        $this->tugasDisetujui('utama', 60);     // utama% = 60
        $this->tugasDisetujui('tambahan', 50);  // tambahan% = 50

        $r = $this->hitungBaris();

        // total = (80*40 + 60*50 + 50*10) / 100 = (3200 + 3000 + 500)/100 = 67
        $this->assertSame(67.0, (float) $r['persen_total']);
        $this->assertSame(80.0, (float) $r['persen_kehadiran']);
        $this->assertSame(60.0, (float) $r['persen_utama']);
        $this->assertSame(50.0, (float) $r['persen_tambahan']);
    }

    public function test_komponen_dibatasi_100_persen(): void
    {
        $this->buatKatimker();
        $this->fakeKehadiran(true, 150.0); // di-cap ke 100
        IncentiveConfig::global()->update([
            'target_utama_menit' => 100, 'target_tambahan_menit' => 100,
        ]);
        $this->tugasDisetujui('utama', 500);    // > target → cap 100
        $this->tugasDisetujui('tambahan', 500); // > target → cap 100

        $r = $this->hitungBaris();

        $this->assertSame(100.0, (float) $r['persen_kehadiran']);
        $this->assertSame(100.0, (float) $r['persen_utama']);
        $this->assertSame(100.0, (float) $r['persen_tambahan']);
        $this->assertSame(100.0, (float) $r['persen_total']);
    }

    public function test_fallback_target_individu_mengalahkan_global(): void
    {
        $this->buatKatimker();
        $this->fakeKehadiran(false, 0.0);
        IncentiveConfig::global()->update(['target_utama_menit' => 1000, 'target_tambahan_menit' => 1000]);
        // Override individu periodik: target utama 100.
        IndividualTarget::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'tahun' => $this->tahun, 'bulan' => $this->bulan,
            'target_utama_menit' => 100, 'target_tambahan_menit' => 100,
        ]);
        $this->tugasDisetujui('utama', 100); // 100/100 = 100% (bukan 10% dari global)

        $r = $this->hitungBaris();

        $this->assertSame(100, $r['target_utama_menit']);
        $this->assertSame(100.0, (float) $r['persen_utama']);
    }

    public function test_plafon_override_mengalahkan_global_default(): void
    {
        $this->buatKatimker();
        $this->fakeKehadiran(true, 100.0);
        IncentiveConfig::global()->update([
            'bobot_kehadiran' => 100, 'bobot_utama' => 0, 'bobot_tambahan' => 0,
            'nominal_dasar' => 1000000,
        ]);

        // Tanpa override → pakai global default.
        $r1 = $this->hitungBaris();
        $this->assertSame(1000000, $r1['nominal_plafon']);
        $this->assertSame(1000000, $r1['nominal_insentif']); // total 100%

        // Dengan override → pakai override.
        IncentivePlafonOverride::create([
            'katimker_sikep_user_id' => $this->katimkerId,
            'tahun' => $this->tahun, 'bulan' => $this->bulan,
            'nominal' => 2500000,
        ]);
        $r2 = $this->hitungBaris();
        $this->assertSame(2500000, $r2['nominal_plafon']);
        $this->assertSame(2500000, $r2['nominal_insentif']);
    }

    public function test_tanpa_override_dan_tanpa_global_default_plafon_nol(): void
    {
        $this->buatKatimker();
        $this->fakeKehadiran(true, 100.0);
        IncentiveConfig::global()->update(['nominal_dasar' => null]);

        $r = $this->hitungBaris();
        $this->assertSame(0, $r['nominal_plafon']);
        $this->assertSame(0, $r['nominal_insentif']);
    }

    public function test_pembulatan_ke_rupiah_penuh(): void
    {
        $this->buatKatimker();
        $this->fakeKehadiran(true, 100.0);
        IncentiveConfig::global()->update([
            'bobot_kehadiran' => 100, 'bobot_utama' => 0, 'bobot_tambahan' => 0,
            'target_utama_menit' => 100, 'target_tambahan_menit' => 100,
            'nominal_dasar' => 1000000,
        ]);
        // Kehadiran 33.33% → insentif = round(1000000 * 33.33 / 100) = 333300.
        $this->fakeKehadiran(true, 33.33);

        $r = $this->hitungBaris();
        $this->assertIsInt($r['nominal_insentif']);
        $this->assertSame(333300, $r['nominal_insentif']);
    }

    public function test_target_nol_memberi_flag_dan_persen_nol(): void
    {
        $this->buatKatimker();
        $this->fakeKehadiran(false, 0.0);
        IncentiveConfig::global()->update(['target_utama_menit' => null, 'target_tambahan_menit' => 0]);
        $this->tugasDisetujui('utama', 500);
        $this->tugasDisetujui('tambahan', 500);

        $r = $this->hitungBaris();
        $this->assertTrue($r['target_utama_belum_diset']);
        $this->assertTrue($r['target_tambahan_belum_diset']);
        $this->assertSame(0.0, (float) $r['persen_utama']);
        $this->assertSame(0.0, (float) $r['persen_tambahan']);
    }

    public function test_kehadiran_tidak_tersedia_nol(): void
    {
        $this->buatKatimker();
        $this->fakeKehadiran(false, 99.0);

        $r = $this->hitungBaris();
        $this->assertFalse($r['kehadiran_tersedia']);
        $this->assertSame(0.0, (float) $r['persen_kehadiran']);
    }

    public function test_finalkan_upsert_dan_audit(): void
    {
        $this->buatKatimker();
        $this->fakeKehadiran(true, 100.0);
        IncentiveConfig::global()->update([
            'bobot_kehadiran' => 100, 'bobot_utama' => 0, 'bobot_tambahan' => 0,
            'nominal_dasar' => 1000000,
        ]);

        $jumlah = app(IncentiveCalculatorService::class)->finalkan($this->tahun, $this->bulan, 111);
        $this->assertSame(1, $jumlah);
        $this->assertSame(1, IncentiveResult::count());
        $this->assertDatabaseHas('incentive_results', [
            'katimker_sikep_user_id' => $this->katimkerId,
            'tahun' => $this->tahun, 'bulan' => $this->bulan,
            'nominal_insentif' => 1000000,
        ]);
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'incentive.finalize']);

        // Re-finalisasi menimpa, jumlah baris stabil.
        app(IncentiveCalculatorService::class)->finalkan($this->tahun, $this->bulan, 111);
        $this->assertSame(1, IncentiveResult::count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
