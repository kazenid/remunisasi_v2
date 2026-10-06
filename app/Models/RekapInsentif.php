<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RekapInsentif extends Model
{
    protected $table = 'rekap_insentif';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'persentase_kehadiran' => 'decimal:2',
            'jam_utama_disetujui' => 'decimal:2',
            'target_jam_utama' => 'decimal:2',
            'capaian_jam_utama' => 'decimal:2',
            'jam_tambahan_disetujui' => 'decimal:2',
            'target_jam_tambahan' => 'decimal:2',
            'capaian_jam_tambahan' => 'decimal:2',
            'skor_kehadiran' => 'decimal:2',
            'skor_utama' => 'decimal:2',
            'skor_tambahan' => 'decimal:2',
            'total_persen_kinerja' => 'decimal:2',
            'nominal_dasar' => 'decimal:2',
            'nominal_insentif' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function periode()
    {
        return $this->belongsTo(PeriodeKinerja::class, 'periode_id');
    }
}
