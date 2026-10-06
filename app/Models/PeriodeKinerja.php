<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodeKinerja extends Model
{
    protected $table = 'periode_kinerja';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'bulan' => 'integer',
            'target_jam_utama' => 'decimal:2',
            'target_jam_tambahan' => 'decimal:2',
            'bobot_kehadiran' => 'decimal:2',
            'bobot_tugas_utama' => 'decimal:2',
            'bobot_tugas_tambahan' => 'decimal:2',
            'nominal_dasar' => 'decimal:2',
        ];
    }

    public function tugasHarian()
    {
        return $this->hasMany(TugasHarian::class, 'periode_id');
    }

    public function rekapInsentif()
    {
        return $this->hasMany(RekapInsentif::class, 'periode_id');
    }

    public static function getActive()
    {
        $now = now();
        return static::where('status', 'aktif')->first()
            ?? static::where('tahun', $now->year)->where('bulan', $now->month)->first()
            ?? static::firstOrCreate(
                ['tahun' => $now->year, 'bulan' => $now->month],
                [
                    'nama' => $now->translatedFormat('F Y'),
                    'tgl_mulai' => 1,
                    'tgl_cutoff' => 10,
                    'tgl_cair' => 15,
                    'target_jam_utama' => 150.00,
                    'target_jam_tambahan' => 15.00,
                    'bobot_kehadiran' => 40.00,
                    'bobot_tugas_utama' => 50.00,
                    'bobot_tugas_tambahan' => 10.00,
                    'nominal_dasar' => 10000000.00,
                    'status' => 'aktif',
                ]
            );
    }
}
