<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Periode bulanan untuk rekap kinerja.
 *
 * @property int $id
 * @property int $tahun
 * @property int $bulan
 * @property string $status
 */
class Period extends Model
{
    protected $fillable = ['tahun', 'bulan', 'cut_off', 'target_cair', 'status'];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'bulan' => 'integer',
            'cut_off' => 'date',
            'target_cair' => 'date',
        ];
    }

    public const STATUS_TERBUKA = 'terbuka';

    public const STATUS_TERKUNCI = 'terkunci';

    /**
     * Dapatkan (atau buat) periode untuk sebuah tanggal, dengan default
     * cut-off tgl 10 dan target cair tgl 15 (dapat dikonfigurasi lanjut).
     */
    public static function forDate(CarbonInterface $date): self
    {
        $tahun = (int) $date->year;
        $bulan = (int) $date->month;

        return static::firstOrCreate(
            ['tahun' => $tahun, 'bulan' => $bulan],
            [
                'cut_off' => $date->copy()->startOfMonth()->day(10),
                'target_cair' => $date->copy()->startOfMonth()->day(15),
                'status' => self::STATUS_TERBUKA,
            ]
        );
    }
}
