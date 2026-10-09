<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Override plafon insentif per individu Katimker pada satu periode.
 * Bila ada, mengalahkan nominal dasar global (incentive_configs.nominal_dasar).
 *
 * @property int $katimker_sikep_user_id
 * @property int $tahun
 * @property int $bulan
 * @property int $nominal
 * @property int|null $di_set_oleh
 */
class IncentivePlafonOverride extends Model
{
    protected $fillable = [
        'katimker_sikep_user_id', 'tahun', 'bulan', 'nominal', 'di_set_oleh',
    ];

    protected function casts(): array
    {
        return [
            'katimker_sikep_user_id' => 'integer',
            'tahun' => 'integer',
            'bulan' => 'integer',
            'nominal' => 'integer',
            'di_set_oleh' => 'integer',
        ];
    }
}
