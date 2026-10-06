<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TugasBukti extends Model
{
    protected $table = 'tugas_bukti';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'accuracy' => 'decimal:2',
            'waktu_ambil' => 'datetime',
        ];
    }

    public function tugasHarian()
    {
        return $this->belongsTo(TugasHarian::class, 'tugas_harian_id');
    }
}
