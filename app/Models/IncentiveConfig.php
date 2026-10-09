<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Konfigurasi parameter insentif. Pada tahap ini hanya DISIMPAN,
 * belum dipakai untuk perhitungan/pembayaran (menunggu parameter final RSUD).
 *
 * @property int|null $tahun
 * @property int|null $bulan
 * @property int|null $target_utama_menit
 * @property int|null $target_tambahan_menit
 * @property int $bobot_kehadiran
 * @property int $bobot_utama
 * @property int $bobot_tambahan
 * @property int|null $nominal_dasar
 * @property bool $aktif
 */
class IncentiveConfig extends Model
{
    protected $fillable = [
        'tahun', 'bulan',
        'target_utama_menit', 'target_tambahan_menit',
        'bobot_kehadiran', 'bobot_utama', 'bobot_tambahan',
        'nominal_dasar', 'aktif', 'diubah_oleh',
    ];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'bulan' => 'integer',
            'target_utama_menit' => 'integer',
            'target_tambahan_menit' => 'integer',
            'bobot_kehadiran' => 'integer',
            'bobot_utama' => 'integer',
            'bobot_tambahan' => 'integer',
            'nominal_dasar' => 'integer',
            'aktif' => 'boolean',
            'diubah_oleh' => 'integer',
        ];
    }

    /**
     * Konfigurasi default global (tahun & bulan null).
     */
    public static function global(): self
    {
        return static::firstOrCreate(
            ['tahun' => null, 'bulan' => null],
            ['bobot_kehadiran' => 40, 'bobot_utama' => 50, 'bobot_tambahan' => 10, 'aktif' => true],
        );
    }
}
