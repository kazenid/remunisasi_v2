<?php

namespace App\Models\Sikep;

use Illuminate\Database\Eloquent\Model;

class Khusus extends Model
{
    protected $connection = 'sikep';
    protected $table = 'ms_khusus';
    public $timestamps = false;

    protected $guarded = [];
}
