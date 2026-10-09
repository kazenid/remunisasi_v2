<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Override target per individu Katimker (mengalahkan target global).
 *
 * @property int $katimker_sikep_user_id
 * @property int|null $tahun
 * @property int|null $bulan
 * @property int|null $target_utama_menit
 * @property int|null $target_tambahan_menit
 */
class IndividualTarget extends Model
{
    protected $fillable = [
        'katimker_sikep_user_id', 'tahun', 'bulan',
        'target_utama_menit', 'target_tambahan_menit', 'di_set_oleh',
    ];

    protected function casts(): array
    {
        return [
            'katimker_sikep_user_id' => 'integer',
            'tahun' => 'integer',
            'bulan' => 'integer',
            'target_utama_menit' => 'integer',
            'target_tambahan_menit' => 'integer',
            'di_set_oleh' => 'integer',
        ];
    }
}
