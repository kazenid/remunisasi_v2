<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Hasil perhitungan insentif yang difinalkan per Katimker per periode.
 * Ditulis hanya ketika Admin menekan "Finalkan"; re-finalisasi menimpa.
 *
 * @property int $katimker_sikep_user_id
 * @property int $tahun
 * @property int $bulan
 * @property float $persen_kehadiran
 * @property float $persen_utama
 * @property float $persen_tambahan
 * @property float $persen_total
 * @property int $nominal_plafon
 * @property int $nominal_insentif
 * @property int $utama_menit
 * @property int $tambahan_menit
 * @property int|null $target_utama_menit
 * @property int|null $target_tambahan_menit
 * @property int $bobot_kehadiran
 * @property int $bobot_utama
 * @property int $bobot_tambahan
 * @property int|null $difinalkan_oleh
 * @property Carbon|null $difinalkan_pada
 */
class IncentiveResult extends Model
{
    protected $fillable = [
        'katimker_sikep_user_id', 'tahun', 'bulan',
        'persen_kehadiran', 'persen_utama', 'persen_tambahan', 'persen_total',
        'nominal_plafon', 'nominal_insentif',
        'utama_menit', 'tambahan_menit', 'target_utama_menit', 'target_tambahan_menit',
        'bobot_kehadiran', 'bobot_utama', 'bobot_tambahan',
        'difinalkan_oleh', 'difinalkan_pada',
    ];

    protected function casts(): array
    {
        return [
            'katimker_sikep_user_id' => 'integer',
            'tahun' => 'integer',
            'bulan' => 'integer',
            'persen_kehadiran' => 'decimal:2',
            'persen_utama' => 'decimal:2',
            'persen_tambahan' => 'decimal:2',
            'persen_total' => 'decimal:2',
            'nominal_plafon' => 'integer',
            'nominal_insentif' => 'integer',
            'utama_menit' => 'integer',
            'tambahan_menit' => 'integer',
            'target_utama_menit' => 'integer',
            'target_tambahan_menit' => 'integer',
            'bobot_kehadiran' => 'integer',
            'bobot_utama' => 'integer',
            'bobot_tambahan' => 'integer',
            'difinalkan_oleh' => 'integer',
            'difinalkan_pada' => 'datetime',
        ];
    }
}
