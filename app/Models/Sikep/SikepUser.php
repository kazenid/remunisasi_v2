<?php

namespace App\Models\Sikep;

use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only: tabel ms_user (SIKEP).
 *
 * Kolom relevan: id, token_pegawai, no_absen, no_pegawai, username, password,
 * nama, gelar_depan, gelar_belakang, aktif.
 *
 * @property int $id
 * @property string|null $token_pegawai
 * @property string|null $username
 * @property int|null $no_absen
 * @property string|null $no_pegawai
 * @property string|null $password
 * @property string|null $nama
 * @property string|null $gelar_depan
 * @property string|null $gelar_belakang
 * @property int $aktif
 */
class SikepUser extends SikepModel
{
    protected $table = 'ms_user';

    /** ID jabatan lapangan untuk penentuan peran (lihat ms_jabatan_lapangan). */
    public const JABATAN_KEPALA_TIM = 3;

    public const JABATAN_KABID = [10, 11, 12];

    /**
     * Cari user aktif berdasarkan username, no_absen, ATAU no_pegawai (sesuai login SIKEP).
     */
    public static function findByLogin(string $login): ?self
    {
        return static::query()
            ->where('aktif', 1)
            ->where(function (Builder $q) use ($login) {
                $q->where('username', $login)
                    ->orWhere('no_absen', $login)
                    ->orWhere('no_pegawai', $login);
            })
            ->first();
    }

    /**
     * Nama lengkap dengan gelar depan/belakang (dirapikan dari spasi berlebih).
     */
    public function getNamaLengkapAttribute(): string
    {
        $parts = array_filter([
            trim((string) $this->gelar_depan),
            trim((string) $this->nama),
            trim((string) $this->gelar_belakang),
        ], fn ($p) => $p !== '');

        return trim(implode(' ', $parts));
    }

    /**
     * Apakah user ini terdaftar sebagai Kepala Tim (Katimker) di SIKEP.
     */
    public function isKatimker(): bool
    {
        return SikepJabatanLapanganUser::query()
            ->where('id_user', $this->id)
            ->where('id_jabatan_lapangan', self::JABATAN_KEPALA_TIM)
            ->exists();
    }

    /**
     * Apakah user ini terdaftar sebagai Kabid di SIKEP.
     */
    public function isKabid(): bool
    {
        return SikepJabatanLapanganUser::query()
            ->where('id_user', $this->id)
            ->whereIn('id_jabatan_lapangan', self::JABATAN_KABID)
            ->exists();
    }
}
