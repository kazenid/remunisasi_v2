<?php

namespace App\Models\Sikep;

/**
 * Read-only: tabel ms_unit (SIKEP).
 *
 * @property int $id
 * @property string|null $nama
 * @property int $aktif
 */
class SikepUnit extends SikepModel
{
    protected $table = 'ms_unit';
}
