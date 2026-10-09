<?php

namespace App\Models\Sikep;

/**
 * Read-only: tabel pivot ms_unit_user (SIKEP).
 * Kolom: id_unit, id_user. Tanpa primary key.
 *
 * @property int|null $id_unit
 * @property int|null $id_user
 */
class SikepUnitUser extends SikepModel
{
    protected $table = 'ms_unit_user';

    protected $primaryKey = null;

    public $incrementing = false;
}
