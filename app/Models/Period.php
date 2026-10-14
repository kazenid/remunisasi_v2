<?php

namespace App\Models;

use Carbon\Carbon;
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

    /** Zona waktu bisnis (config app masih UTC). */
    public const TZ = 'Asia/Jakarta';

    /** Tanggal hari ini menurut zona waktu bisnis. */
    public static function today(): CarbonInterface
    {
        return now(self::TZ)->startOfDay();
    }

    /** Cut-off bawaan periode: tgl 10 bulan BERIKUTNYA. */
    public static function defaultCutOff(int $tahun, int $bulan): CarbonInterface
    {
        return Carbon::create($tahun, $bulan, 1)->addMonthNoOverflow()->day(10);
    }

    /** Target realisasi bawaan periode: tgl 15 bulan BERIKUTNYA. */
    public static function defaultTargetRealisasi(int $tahun, int $bulan): CarbonInterface
    {
        return Carbon::create($tahun, $bulan, 1)->addMonthNoOverflow()->day(15);
    }

    /**
     * Dapatkan (atau buat) periode untuk sebuah tanggal. Cut-off & target
     * realisasi bawaan jatuh di bulan berikutnya (dapat diubah admin).
     */
    public static function forDate(CarbonInterface $date): self
    {
        $tahun = (int) $date->year;
        $bulan = (int) $date->month;

        return static::firstOrCreate(
            ['tahun' => $tahun, 'bulan' => $bulan],
            [
                'cut_off' => self::defaultCutOff($tahun, $bulan),
                'target_cair' => self::defaultTargetRealisasi($tahun, $bulan),
                'status' => self::STATUS_TERBUKA,
            ]
        );
    }

    /** Cut-off efektif (record bila ada, jika tidak bawaan). */
    public function cutOffEfektif(): CarbonInterface
    {
        return $this->cut_off ?? self::defaultCutOff($this->tahun, $this->bulan);
    }

    /**
     * Periode terkunci bila admin menguncinya, atau hari ini sudah mencapai
     * tanggal cut-off (pada hari cut-off periode sudah tidak menerima
     * input maupun penilaian).
     */
    public function isLocked(?CarbonInterface $hariIni = null): bool
    {
        $hariIni ??= self::today();

        return $this->status === self::STATUS_TERKUNCI
            || $hariIni->toDateString() >= $this->cutOffEfektif()->toDateString();
    }

    /** Terkunci atau tidak untuk bulan tertentu, tanpa harus ada record-nya. */
    public static function lockedFor(int $tahun, int $bulan, ?CarbonInterface $hariIni = null): bool
    {
        $period = static::query()->where('tahun', $tahun)->where('bulan', $bulan)->first()
            ?? new static(['tahun' => $tahun, 'bulan' => $bulan, 'status' => self::STATUS_TERBUKA]);

        return $period->isLocked($hariIni);
    }

    /** Info periode (tanpa membuat record) untuk ditampilkan. */
    public static function info(int $tahun, int $bulan): array
    {
        $p = static::query()->where('tahun', $tahun)->where('bulan', $bulan)->first();

        return [
            'tahun' => $tahun,
            'bulan' => $bulan,
            'status' => $p?->status ?? self::STATUS_TERBUKA,
            'cut_off' => ($p?->cut_off ?? self::defaultCutOff($tahun, $bulan))->toDateString(),
            'target_realisasi' => ($p?->target_cair ?? self::defaultTargetRealisasi($tahun, $bulan))->toDateString(),
            'terkunci' => ($p ?? new static(['tahun' => $tahun, 'bulan' => $bulan, 'status' => self::STATUS_TERBUKA]))->isLocked(),
        ];
    }
}
