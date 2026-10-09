<?php

namespace App\Services;

use App\Models\IncentiveConfig;
use App\Models\IncentivePlafonOverride;
use App\Models\IncentiveResult;
use Illuminate\Support\Collection;

/**
 * Mesin perhitungan insentif per Katimker per periode (bulan + tahun).
 *
 * Formula per pegawai (bobot dari IncentiveConfig::global(), default 40/50/10):
 *   kehadiran% = persen_kehadiran mentah dari AttendanceService (0–100), 0 bila tidak tersedia.
 *   utama%     = min(100, utama_menit / target_utama_menit * 100), 0 bila target null/0.
 *   tambahan%  = min(100, tambahan_menit / target_tambahan_menit * 100), 0 bila target null/0.
 *   total%     = (kehadiran%·bobot_kehadiran + utama%·bobot_utama + tambahan%·bobot_tambahan) / 100.
 *   plafon     = override periode (incentive_plafon_overrides) ?? nominal_dasar global ?? 0.
 *   insentif   = round(plafon · total% / 100) rupiah penuh.
 *
 * Tidak ada perhitungan pengurangan insentif di sini — AttendanceService sudah
 * menghasilkan persen_kehadiran mentah; mesin ini hanya mengonsumsinya.
 */
class IncentiveCalculatorService
{
    public function __construct(
        private readonly RecapService $recap,
        private readonly AttendanceService $attendance,
        private readonly TargetResolver $target,
        private readonly ApproverMappingService $mapping,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Hitung simulasi insentif seluruh Katimker aktif untuk satu periode.
     *
     * @return Collection<int, array<string,mixed>>
     */
    public function hitung(int $tahun, int $bulan): Collection
    {
        $katimker = $this->mapping->listKatimker();
        $ids = $katimker->pluck('sikep_user_id');

        $cfg = IncentiveConfig::global();
        $nominalDasar = (int) ($cfg->nominal_dasar ?? 0);

        // Rekap jam seluruh Katimker dalam satu lintasan (hindari N+1 di DB aplikasi).
        $rekap = $this->recap->forKatimkerList($ids, $tahun, $bulan)
            ->keyBy('katimker_sikep_user_id');

        // Override plafon periode, satu query, keyed per katimker.
        $overrides = IncentivePlafonOverride::query()
            ->whereIn('katimker_sikep_user_id', $ids)
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->get()
            ->keyBy('katimker_sikep_user_id');

        return $katimker->map(function (array $k) use ($rekap, $overrides, $cfg, $nominalDasar, $tahun, $bulan) {
            $id = $k['sikep_user_id'];

            // Komponen kehadiran (panggilan SIKEP per orang — dapat diterima).
            $absen = $k['no_absen'] !== null
                ? $this->attendance->nilai($id, (int) $k['no_absen'], $tahun, $bulan)
                : ['tersedia' => false, 'persen_kehadiran' => 0.0];

            $kehadiranTersedia = (bool) ($absen['tersedia'] ?? false);
            $persenKehadiran = $kehadiranTersedia
                ? max(0.0, min(100.0, (float) ($absen['persen_kehadiran'] ?? 0)))
                : 0.0;

            // Rekap menit tugas.
            $r = $rekap->get($id, ['utama_menit' => 0, 'tambahan_menit' => 0]);
            $utamaMenit = (int) ($r['utama_menit'] ?? 0);
            $tambahanMenit = (int) ($r['tambahan_menit'] ?? 0);

            // Target efektif (individu periodik → individu umum → global).
            $target = $this->target->efektif($id, $tahun, $bulan);
            $targetUtama = $target['utama_menit'];
            $targetTambahan = $target['tambahan_menit'];

            $targetUtamaBelumDiset = ! ($targetUtama > 0);
            $targetTambahanBelumDiset = ! ($targetTambahan > 0);

            $persenUtama = $targetUtamaBelumDiset ? 0.0 : min(100.0, $utamaMenit / $targetUtama * 100);
            $persenTambahan = $targetTambahanBelumDiset ? 0.0 : min(100.0, $tambahanMenit / $targetTambahan * 100);

            $persenTotal = (
                $persenKehadiran * $cfg->bobot_kehadiran
                + $persenUtama * $cfg->bobot_utama
                + $persenTambahan * $cfg->bobot_tambahan
            ) / 100;

            $plafon = isset($overrides[$id]) ? (int) $overrides[$id]->nominal : $nominalDasar;
            $insentif = (int) round($plafon * $persenTotal / 100);

            return [
                'katimker_sikep_user_id' => $id,
                'nama' => $k['nama'],
                'persen_kehadiran' => round($persenKehadiran, 2),
                'kehadiran_tersedia' => $kehadiranTersedia,
                'utama_menit' => $utamaMenit,
                'target_utama_menit' => $targetUtama,
                'persen_utama' => round($persenUtama, 2),
                'target_utama_belum_diset' => $targetUtamaBelumDiset,
                'tambahan_menit' => $tambahanMenit,
                'target_tambahan_menit' => $targetTambahan,
                'persen_tambahan' => round($persenTambahan, 2),
                'target_tambahan_belum_diset' => $targetTambahanBelumDiset,
                'persen_total' => round($persenTotal, 2),
                'nominal_plafon' => $plafon,
                'nominal_insentif' => $insentif,
                'bobot_kehadiran' => $cfg->bobot_kehadiran,
                'bobot_utama' => $cfg->bobot_utama,
                'bobot_tambahan' => $cfg->bobot_tambahan,
            ];
        })->values();
    }

    /**
     * Finalkan hasil periode: upsert incentive_results per Katimker (re-finalisasi
     * menimpa) + satu entri audit. Mengembalikan jumlah baris yang difinalkan.
     */
    public function finalkan(int $tahun, int $bulan, ?int $aktorSikepUserId): int
    {
        $baris = $this->hitung($tahun, $bulan);
        $sekarang = now();

        foreach ($baris as $row) {
            IncentiveResult::updateOrCreate(
                [
                    'katimker_sikep_user_id' => $row['katimker_sikep_user_id'],
                    'tahun' => $tahun,
                    'bulan' => $bulan,
                ],
                [
                    'persen_kehadiran' => $row['persen_kehadiran'],
                    'persen_utama' => $row['persen_utama'],
                    'persen_tambahan' => $row['persen_tambahan'],
                    'persen_total' => $row['persen_total'],
                    'nominal_plafon' => $row['nominal_plafon'],
                    'nominal_insentif' => $row['nominal_insentif'],
                    'utama_menit' => $row['utama_menit'],
                    'tambahan_menit' => $row['tambahan_menit'],
                    'target_utama_menit' => $row['target_utama_menit'],
                    'target_tambahan_menit' => $row['target_tambahan_menit'],
                    'bobot_kehadiran' => $row['bobot_kehadiran'],
                    'bobot_utama' => $row['bobot_utama'],
                    'bobot_tambahan' => $row['bobot_tambahan'],
                    'difinalkan_oleh' => $aktorSikepUserId,
                    'difinalkan_pada' => $sekarang,
                ],
            );
        }

        $jumlah = $baris->count();

        $this->audit->record($aktorSikepUserId, 'incentive.finalize', null, [
            'tahun' => $tahun,
            'bulan' => $bulan,
            'jumlah_pegawai' => $jumlah,
        ]);

        return $jumlah;
    }
}
