<?php

namespace App\Services;

use App\Models\TaskReport;
use Illuminate\Support\Collection;

/**
 * Rekap jam yang DISETUJUI per periode (bulan, tahun).
 * Hanya tugas berstatus "disetujui" yang dihitung. MVP: rekap jam saja,
 * tanpa perhitungan insentif.
 */
class RecapService
{
    /**
     * Rekap jam untuk satu Katimker pada satu periode.
     *
     * @return array{utama_menit:int, tambahan_menit:int, total_menit:int}
     */
    public function forPeriod(int $katimkerSikepUserId, int $tahun, int $bulan): array
    {
        $rows = TaskReport::query()
            ->milik($katimkerSikepUserId)
            ->periode($tahun, $bulan)
            ->where('status', TaskReport::STATUS_DISETUJUI)
            ->selectRaw('jenis, COALESCE(SUM(durasi_disetujui_menit),0) as total')
            ->groupBy('jenis')
            ->pluck('total', 'jenis');

        $utama = (int) ($rows[TaskReport::JENIS_UTAMA] ?? 0);
        $tambahan = (int) ($rows[TaskReport::JENIS_TAMBAHAN] ?? 0);

        return [
            'utama_menit' => $utama,
            'tambahan_menit' => $tambahan,
            'total_menit' => $utama + $tambahan,
        ];
    }

    /**
     * Rekap untuk sekumpulan Katimker (dipakai Kabid untuk binaannya, atau Admin lintas).
     *
     * @param  Collection<int,int>|array<int,int>  $katimkerIds
     * @return Collection<int, array{katimker_sikep_user_id:int, utama_menit:int, tambahan_menit:int, total_menit:int}>
     */
    public function forKatimkerList(iterable $katimkerIds, int $tahun, int $bulan): Collection
    {
        return collect($katimkerIds)
            ->unique()
            ->map(fn (int $id) => array_merge(
                ['katimker_sikep_user_id' => $id],
                $this->forPeriod($id, $tahun, $bulan),
            ))
            ->values();
    }
}
