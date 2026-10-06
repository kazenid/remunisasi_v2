<?php

namespace App\Models\Sikep;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $connection = 'sikep';
    protected $table = 'ms_unit';
    public $timestamps = false;

    protected $guarded = [];

    public function users()
    {
        return $this->belongsToMany(UserSikep::class, 'ms_unit_user', 'id_unit', 'id_user');
    }
}
