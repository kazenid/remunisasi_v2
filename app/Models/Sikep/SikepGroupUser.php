<?php

namespace App\Models\Sikep;

/**
 * Read-only: tabel pivot ms_group_user (SIKEP).
 * Kolom: id_group, id_user. Tanpa primary key.
 * Dipakai sebagai penguat opsional penentuan peran (group 7 = "Ka. Tim").
 *
 * @property int|null $id_group
 * @property int|null $id_user
 */
class SikepGroupUser extends SikepModel
{
    protected $table = 'ms_group_user';

    protected $primaryKey = null;

    public $incrementing = false;
}
