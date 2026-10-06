<?php

namespace App\Models\Sikep;

use Illuminate\Database\Eloquent\Model;

class UserSikep extends Model
{
    protected $connection = 'sikep';
    protected $table = 'ms_user';
    public $timestamps = false;

    protected $guarded = [];

    public function pegawai()
    {
        return $this->belongsTo(Pegawai::class, 'id', 'id');
    }

    public function getNamaLengkapAttribute(): string
    {
        $depan = $this->gelar_depan ? trim($this->gelar_depan) . ' ' : '';
        $belakang = $this->gelar_belakang ? ', ' . trim($this->gelar_belakang) : '';
        return $depan . trim($this->nama) . $belakang;
    }
}
