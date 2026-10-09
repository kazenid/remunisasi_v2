<?php

namespace App\Models\Sikep;

/**
 * Read-only: tabel pivot ms_jabatan_lapangan_user (SIKEP).
 * Kolom: id_jabatan_lapangan, id_user. Tanpa primary key.
 *
 * @property int|null $id_jabatan_lapangan
 * @property int|null $id_user
 */
class SikepJabatanLapanganUser extends SikepModel
{
    protected $table = 'ms_jabatan_lapangan_user';

    protected $primaryKey = null;

    public $incrementing = false;
}
