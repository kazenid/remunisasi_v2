<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'is_katimker' => 'boolean',
        ];
    }

    public function tugasHarian()
    {
        return $this->hasMany(TugasHarian::class, 'user_id');
    }

    /**
     * Atasan langsung (approver) bagi pegawai yang berperan sebagai Ketua Tim Kerja.
     */
    public function atasanLangsung()
    {
        return $this->belongsTo(User::class, 'atasan_langsung_id');
    }

    /**
     * Pegawai-pegawai yang atasan langsungnya adalah user ini.
     */
    public function bawahanLangsung()
    {
        return $this->hasMany(User::class, 'atasan_langsung_id');
    }

    public function rekapInsentif()
    {
        return $this->hasMany(RekapInsentif::class, 'user_id');
    }

    public function persetujuanKinerja()
    {
        return $this->hasMany(PersetujuanKinerja::class, 'approver_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isDirektur(): bool
    {
        return in_array($this->role, ['direktur', 'admin']);
    }

    public function isWadir(): bool
    {
        return in_array($this->role, ['wadir', 'direktur', 'admin']);
    }

    public function isKabid(): bool
    {
        return in_array($this->role, ['kabid', 'wadir', 'direktur', 'admin']);
    }

    public function isKatker(): bool
    {
        return in_array($this->role, ['katker', 'kepala_unit', 'kabid', 'wadir', 'direktur', 'admin']);
    }

    /**
     * Apakah user ini berperan sebagai Ketua Tim Kerja (Katimker).
     *
     * Perdir 186/2026 Pasal 17: Katimker adalah jabatan setingkat di bawah
     * Kepala Instalasi / Kepala Ruangan dengan alur verifikasi kinerja tersendiri.
     */
    public function isKatimker(): bool
    {
        return (bool) $this->is_katimker;
    }

    /**
     * Apakah user ini memiliki bawahan langsung ber-flag Katimker.
     * Dipakai untuk menampilkan antrean verifikasi & badge dashboard.
     */
    public function hasKatimkerStaff(): bool
    {
        return $this->bawahanLangsung()->where('is_katimker', true)->exists();
    }

    /**
     * User ini adalah atasan langsung bagi minimal satu Katimker.
     */
    public function isAtasanKatimker(): bool
    {
        return $this->hasKatimkerStaff();
    }

    public function getNamaLengkapAttribute(): string
    {
        $depan = $this->gelar_depan ? trim($this->gelar_depan) . ' ' : '';
        $belakang = $this->gelar_belakang ? ', ' . trim($this->gelar_belakang) : '';
        return $depan . trim($this->name) . $belakang;
    }
}

