<?php

namespace App\Services;

use App\Models\IncentiveConfig;
use App\Models\IndividualTarget;

/**
 * Menentukan target jam efektif seorang Katimker pada periode:
 * override individu periodik → override individu umum → target global.
 *
 * Target dalam menit (konsisten dgn task_reports). Hanya untuk tampilan &
 * persiapan simulasi; belum dipakai menghitung pembayaran insentif.
 */
class TargetResolver
{
    /**
     * @return array{utama_menit:?int, tambahan_menit:?int, sumber:string}
     */
    public function efektif(int $katimkerSikepUserId, int $tahun, int $bulan): array
    {
        // 1) Override individu untuk periode spesifik.
        $periodik = IndividualTarget::query()
            ->where('katimker_sikep_user_id', $katimkerSikepUserId)
            ->where('tahun', $tahun)->where('bulan', $bulan)
            ->first();
        if ($periodik && ($periodik->target_utama_menit !== null || $periodik->target_tambahan_menit !== null)) {
            return $this->bungkus($periodik->target_utama_menit, $periodik->target_tambahan_menit, 'individu_periodik');
        }

        // 2) Override individu umum (tahun & bulan null).
        $umum = IndividualTarget::query()
            ->where('katimker_sikep_user_id', $katimkerSikepUserId)
            ->whereNull('tahun')->whereNull('bulan')
            ->first();
        if ($umum && ($umum->target_utama_menit !== null || $umum->target_tambahan_menit !== null)) {
            return $this->bungkus($umum->target_utama_menit, $umum->target_tambahan_menit, 'individu_umum');
        }

        // 3) Target global.
        $global = IncentiveConfig::global();

        return $this->bungkus($global->target_utama_menit, $global->target_tambahan_menit, 'global');
    }

    private function bungkus(?int $utama, ?int $tambahan, string $sumber): array
    {
        return [
            'utama_menit' => $utama,
            'tambahan_menit' => $tambahan,
            'sumber' => $sumber,
        ];
    }
}
