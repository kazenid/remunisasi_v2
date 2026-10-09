<?php

namespace App\Models\Sikep;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Base class untuk seluruh model yang membaca database SIKEP (bangil_kepegawaian).
 *
 * SIKEP adalah sumber data READ-ONLY. Kelas ini:
 *  - memaksa koneksi "sikep",
 *  - mematikan timestamps (skema SIKEP tidak punya created_at/updated_at seragam),
 *  - memblokir seluruh operasi tulis sebagai lapis pengaman tambahan di atas
 *    kredensial DB. Operasi tulis apa pun melempar RuntimeException.
 */
abstract class SikepModel extends Model
{
    protected $connection = 'sikep';

    public $timestamps = false;

    /**
     * Guard: blokir INSERT/UPDATE lewat save().
     */
    public function save(array $options = [])
    {
        throw new RuntimeException('SIKEP bersifat read-only: operasi save() tidak diizinkan.');
    }

    /**
     * Guard: blokir DELETE.
     */
    public function delete()
    {
        throw new RuntimeException('SIKEP bersifat read-only: operasi delete() tidak diizinkan.');
    }

    /**
     * Guard: blokir mass update via query builder Eloquent.
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new RuntimeException('SIKEP bersifat read-only: operasi update() tidak diizinkan.');
    }

    /**
     * Guard: blokir forceDelete.
     */
    public function forceDelete()
    {
        throw new RuntimeException('SIKEP bersifat read-only: operasi forceDelete() tidak diizinkan.');
    }
}
