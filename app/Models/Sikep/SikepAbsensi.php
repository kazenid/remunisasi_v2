<?php

namespace App\Models\Sikep;

/**
 * Read-only: tabel absensi (SIKEP) — header absensi per user/bulan/tahun.
 *
 * @property int $id
 * @property int|null $id_user
 * @property int|null $bulan
 * @property int|null $tahun
 */
class SikepAbsensi extends SikepModel
{
    protected $table = 'absensi';
}
