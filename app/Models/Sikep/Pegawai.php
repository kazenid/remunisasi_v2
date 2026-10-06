<?php

namespace App\Models\Sikep;

use Illuminate\Database\Eloquent\Model;

class Pegawai extends Model
{
    protected $connection = 'sikep';
    protected $table = 'v_pegawai';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $guarded = [];
}
