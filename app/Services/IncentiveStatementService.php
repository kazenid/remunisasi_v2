<?php

namespace App\Services;

use App\Models\IncentiveResult;
use App\Models\User;

/**
 * Rincian perolehan insentif satu Katimker pada satu periode (untuk halaman rekap dan slip).
 *
 * Bila periode sudah difinalkan Admin, angka diambil dari snapshot IncentiveResult
 * (status "final"); jika belum, dihitung langsung sebagai perkiraan (status "estimasi").
 */
class IncentiveStatementService
{
    public const STATUS_FINAL = 'final';

    public const STATUS_ESTIMASI = 'estimasi';

    public function __construct(private readonly IncentiveCalculatorService $calc) {}

    /**
     * @return array<string,mixed>
     */
    public function untuk(User $user, int $tahun, int $bulan): array
    {
        $final = IncentiveResult::query()
            ->where('katimker_sikep_user_id', $user->sikep_user_id)
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->first();

        if ($final !== null) {
            $d = [
                'status' => self::STATUS_FINAL,
                'difinalkan_pada' => $final->difinalkan_pada?->toIso8601String(),
                'plafon' => (int) $final->nominal_plafon,
                'nominal' => (int) $final->nominal_insentif,
                'persen_total' => (float) $final->persen_total,
                'kehadiran_tersedia' => true,
                'komponen' => [
                    $this->komponen('kehadiran', 'Kehadiran', $final->bobot_kehadiran, $final->persen_kehadiran, null, null),
                    $this->komponen('utama', 'Tugas Utama', $final->bobot_utama, $final->persen_utama, $final->utama_menit, $final->target_utama_menit),
                    $this->komponen('tambahan', 'Tugas Tambahan', $final->bobot_tambahan, $final->persen_tambahan, $final->tambahan_menit, $final->target_tambahan_menit),
                ],
                'target_utama_belum_diset' => ! ($final->target_utama_menit > 0),
                'target_tambahan_belum_diset' => ! ($final->target_tambahan_menit > 0),
            ];
        } else {
            $h = $this->calc->hitungSatu(
                (int) $user->sikep_user_id,
                (string) $user->nama,
                $user->no_absen ? (int) $user->no_absen : null,
                $tahun,
                $bulan,
            );

            $d = [
                'status' => self::STATUS_ESTIMASI,
                'difinalkan_pada' => null,
                'plafon' => (int) $h['nominal_plafon'],
                'nominal' => (int) $h['nominal_insentif'],
                'persen_total' => (float) $h['persen_total'],
                'kehadiran_tersedia' => (bool) $h['kehadiran_tersedia'],
                'komponen' => [
                    $this->komponen('kehadiran', 'Kehadiran', $h['bobot_kehadiran'], $h['persen_kehadiran'], null, null),
                    $this->komponen('utama', 'Tugas Utama', $h['bobot_utama'], $h['persen_utama'], $h['utama_menit'], $h['target_utama_menit']),
                    $this->komponen('tambahan', 'Tugas Tambahan', $h['bobot_tambahan'], $h['persen_tambahan'], $h['tambahan_menit'], $h['target_tambahan_menit']),
                ],
                'target_utama_belum_diset' => (bool) $h['target_utama_belum_diset'],
                'target_tambahan_belum_diset' => (bool) $h['target_tambahan_belum_diset'],
            ];
        }

        $d['komponen'] = $this->selaraskan($d['komponen'], $d['plafon'], $d['nominal']);
        $d['tahun'] = $tahun;
        $d['bulan'] = $bulan;
        $d['catatan'] = $this->catatan($d);

        return $d;
    }

    /** @return array<string,mixed> */
    private function komponen(string $kode, string $label, int|float $bobot, int|float $persen, ?int $menit, ?int $targetMenit): array
    {
        return [
            'kode' => $kode,
            'label' => $label,
            'bobot' => (float) $bobot,
            'persen' => (float) $persen,
            'menit' => $menit,
            'target_menit' => $targetMenit,
            'maksimal' => 0,
            'diperoleh' => 0,
        ];
    }

    /**
     * Isi nominal maksimal & diperoleh per komponen. Komponen terakhir menyerap
     * selisih pembulatan agar jumlahnya persis sama dengan plafon dan total insentif.
     *
     * @param  array<int,array<string,mixed>>  $komponen
     * @return array<int,array<string,mixed>>
     */
    private function selaraskan(array $komponen, int $plafon, int $nominal): array
    {
        $akhir = count($komponen) - 1;
        $sisaMaks = $plafon;
        $sisaDiperoleh = $nominal;

        foreach ($komponen as $i => &$k) {
            if ($i === $akhir) {
                $k['maksimal'] = $sisaMaks;
                $k['diperoleh'] = $sisaDiperoleh;
            } else {
                $k['maksimal'] = (int) round($plafon * $k['bobot'] / 100);
                $k['diperoleh'] = (int) round($plafon * $k['bobot'] / 100 * $k['persen'] / 100);
                $sisaMaks -= $k['maksimal'];
                $sisaDiperoleh -= $k['diperoleh'];
            }
        }
        unset($k);

        return $komponen;
    }

    /** @return array<int,string> */
    private function catatan(array $d): array
    {
        $c = [];

        if ($d['status'] === self::STATUS_ESTIMASI) {
            $c[] = 'Angka ini masih perkiraan dan dapat berubah sampai Admin memfinalkan periode ini.';
        }
        if (! $d['kehadiran_tersedia']) {
            $c[] = 'Data kehadiran periode ini belum tersedia di SIKEP, sehingga dihitung 0%.';
        }
        if ($d['target_utama_belum_diset']) {
            $c[] = 'Target tugas utama belum diatur, sehingga capaian utama dihitung 0%.';
        }
        if ($d['target_tambahan_belum_diset']) {
            $c[] = 'Target tugas tambahan belum diatur, sehingga capaian tambahan dihitung 0%.';
        }
        if ($d['plafon'] <= 0) {
            $c[] = 'Plafon insentif belum diatur oleh Admin.';
        }

        return $c;
    }
}
