<?php

namespace App\Models\Sikep;

use Illuminate\Database\Eloquent\Model;

class Absensi extends Model
{
    protected $connection = 'sikep';
    protected $table = 'absensi';
    public $timestamps = false;

    protected $guarded = [];

    public function details()
    {
        return $this->hasMany(AbsensiDetail::class, 'id_absensi', 'id');
    }
}
