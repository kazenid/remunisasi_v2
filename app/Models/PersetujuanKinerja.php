<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersetujuanKinerja extends Model
{
    protected $table = 'persetujuan_kinerja';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'jam_disetujui' => 'decimal:2',
        ];
    }

    public function tugasHarian()
    {
        return $this->belongsTo(TugasHarian::class, 'tugas_harian_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
