<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catatan audit append-only.
 *
 * @property int $id
 * @property int|null $aktor_sikep_user_id
 * @property string $aksi
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array|null $meta
 */
class AuditLog extends Model
{
    // Append-only: tidak ada updated_at.
    public const UPDATED_AT = null;

    protected $fillable = [
        'aktor_sikep_user_id',
        'aksi',
        'subject_type',
        'subject_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'aktor_sikep_user_id' => 'integer',
            'subject_id' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
