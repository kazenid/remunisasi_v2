<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master kegiatan: daftar nama kegiatan baku untuk input tugas.
 *
 * @property int $id
 * @property string $nama
 * @property bool $aktif
 */
class Activity extends Model
{
    protected $fillable = ['nama', 'aktif'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function tugas(): HasMany
    {
        return $this->hasMany(TaskReport::class, 'kegiatan_id');
    }

    public function scopeAktif(Builder $q): Builder
    {
        return $q->where('aktif', true);
    }
}
