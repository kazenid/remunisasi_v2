<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pemetaan Katimker → Kabid (one-to-one), ditetapkan manual oleh Admin.
 *
 * @property int $id
 * @property int $katimker_sikep_user_id
 * @property int $kabid_sikep_user_id
 * @property bool $aktif
 * @property int|null $diubah_oleh
 */
class ApproverMapping extends Model
{
    protected $fillable = [
        'katimker_sikep_user_id',
        'kabid_sikep_user_id',
        'aktif',
        'berlaku_mulai',
        'diubah_oleh',
    ];

    protected function casts(): array
    {
        return [
            'katimker_sikep_user_id' => 'integer',
            'kabid_sikep_user_id' => 'integer',
            'aktif' => 'boolean',
            'berlaku_mulai' => 'date',
            'diubah_oleh' => 'integer',
        ];
    }

    /**
     * Kabid aktif untuk seorang Katimker (atau null bila belum dipetakan).
     */
    public static function kabidFor(int $katimkerSikepUserId): ?int
    {
        $row = static::query()
            ->where('katimker_sikep_user_id', $katimkerSikepUserId)
            ->where('aktif', true)
            ->first();

        return $row?->kabid_sikep_user_id;
    }
}
