<?php

namespace App\Models\Sikep;

use Illuminate\Database\Eloquent\Model;

class AbsensiDetail extends Model
{
    protected $connection = 'sikep';
    protected $table = 'absensi_detail';
    public $timestamps = false;

    protected $guarded = [];

    public function absensi()
    {
        return $this->belongsTo(Absensi::class, 'id_absensi', 'id');
    }
}
