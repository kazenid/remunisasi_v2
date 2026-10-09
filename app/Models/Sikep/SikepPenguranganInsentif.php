<?php

namespace App\Models\Sikep;

/**
 * Read-only: ms_pengurangan_insentif (SIKEP).
 * Aturan persentase pengurangan insentif per kategori & rentang menit.
 *
 * Kategori (ms_pengurangan_insentif_kategori):
 *   1 = Reguler (terlambat/pulang awal, berjenjang menit)
 *   2 = Tidak Scan
 *   3 = Sakit
 *   4 = Cuti
 *   5 = TK (tanpa keterangan)
 *   6 = DL (dinas luar)
 *
 * @property int $id
 * @property int $id_ms_pengurangan_insentif_kategori
 * @property float|null $prosentase
 * @property int|null $menit_mulai
 * @property int|null $menit_akhir
 */
class SikepPenguranganInsentif extends SikepModel
{
    protected $table = 'ms_pengurangan_insentif';

    public const KAT_REGULER = 1;

    public const KAT_TIDAK_SCAN = 2;

    public const KAT_SAKIT = 3;

    public const KAT_CUTI = 4;

    public const KAT_TK = 5;

    public const KAT_DL = 6;
}
