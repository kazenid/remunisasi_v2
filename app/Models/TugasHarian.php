<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TugasHarian extends Model
{
    protected $table = 'tugas_harian';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date:Y-m-d',
            'durasi_jam' => 'decimal:2',
            'jam_disetujui' => 'decimal:2',
            'tgl_pengajuan' => 'datetime',
            'tgl_selesai_approval' => 'datetime',
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

    public function bukti()
    {
        return $this->hasMany(TugasBukti::class, 'tugas_harian_id');
    }

    public function riwayatPersetujuan()
    {
        return $this->hasMany(PersetujuanKinerja::class, 'tugas_harian_id')->orderBy('created_at', 'asc');
    }
}
