<?php

namespace App\Models;

use App\Models\Sikep\SikepUser;
use App\Services\Sikep\RoleResolver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * User lokal: mirror ringan dari ms_user SIKEP.
 * Password tidak disimpan; autentikasi dilakukan terhadap SIKEP.
 *
 * @property int $id
 * @property int $sikep_user_id
 * @property string|null $username
 * @property string|null $nama
 * @property int|null $no_absen
 * @property string|null $token_pegawai
 * @property bool $is_admin
 * @property string|null $role
 * @property bool $aktif
 * @property string|null $two_factor_secret
 * @property array<int, string> $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 */
#[Fillable([
    'sikep_user_id', 'username', 'nama', 'no_absen', 'token_pegawai', 'is_admin', 'role', 'aktif', 'last_sync_at',
    'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
])]
#[Hidden(['remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_KATIMKER = 'katimker';

    public const ROLE_KABID = 'kabid';

    /** @var list<string> */
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_KATIMKER, self::ROLE_KABID];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'aktif' => 'boolean',
            'last_sync_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Set peran sekaligus menjaga invarian role=admin <=> is_admin=true.
     * SATU-SATUNYA jalur tulis `role`/`is_admin` yang hidup (kecuali backfill
     * migrasi & UserFactory state yang menulis kedua kolom berpasangan).
     * TIDAK memanggil save(); pemanggil bertanggung jawab menyimpan.
     */
    public function setRole(?string $role): void
    {
        $this->role = $role;
        $this->is_admin = ($role === self::ROLE_ADMIN);
    }

    /**
     * Buat atau perbarui user lokal dari data SIKEP. Tidak menyimpan password.
     */
    public static function syncFromSikep(SikepUser $sikep): self
    {
        return static::updateOrCreate(
            ['sikep_user_id' => $sikep->id],
            [
                'username' => $sikep->username,
                'nama' => $sikep->nama_lengkap,
                'no_absen' => $sikep->no_absen,
                'token_pegawai' => $sikep->token_pegawai,
                'last_sync_at' => now(),
            ]
        );
    }

    /**
     * Peran efektif pengguna (katimker/kabid/admin), diresolusi dari kolom
     * lokal `role` + `aktif` (lepas dari jabatan SIKEP).
     *
     * @return array{roles: string[], is_katimker: bool, is_kabid: bool, is_admin: bool}
     */
    public function roles(): array
    {
        return app(RoleResolver::class)->resolve($this->sikep_user_id);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles()['roles'], true);
    }

    /**
     * Secret TOTP: simpan terenkripsi, baca terdekripsi. null → null.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function twoFactorSecret(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Crypt::decryptString($value),
            set: fn ($value) => $value === null ? null : Crypt::encryptString($value),
        );
    }

    /**
     * Recovery codes: array<string> terenkripsi sebagai JSON.
     * Array kosong dinormalkan → null (habis dan belum-pernah-diisi sama).
     *
     * @return Attribute<array<int, string>, string|null>
     */
    protected function twoFactorRecoveryCodes(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? [] : json_decode(Crypt::decryptString($value), true),
            set: fn ($value) => empty($value) ? null : Crypt::encryptString(json_encode(array_values($value))),
        );
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Dipakai HANYA oleh middleware EnsureTwoFactor (re-resolve per-request),
     * BUKAN oleh LoginController::store() (lihat Invarian Urutan di desain).
     * is_admin bersumber dari flag lokal; RoleResolver degradasi anggun saat
     * SIKEP down → 2FA tetap ditegakkan.
     */
    public function requiresTwoFactor(): bool
    {
        return $this->roles()['is_admin'] === true;
    }

    /**
     * Konsumsi satu recovery code (single-use). Return true bila cocok & dihapus.
     *
     * Normalisasi dipaku di SATU tempat: strip semua karakter non-alfanumerik
     * (tanda hubung, spasi) lalu UPPER-CASE, untuk input DAN tiap kode tersimpan
     * sebelum dibandingkan via hash_equals. Kode tetap DISIMPAN dalam format
     * asli XXXX-XXXX agar tampilan sekali-pakai mudah dibaca.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $codes = $this->two_factor_recovery_codes; // array terdekripsi (format tersimpan XXXX-XXXX)

        $normalize = static fn (string $v): string => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v));
        $input = $normalize($code);

        $matched = false;
        $remaining = [];
        foreach ($codes as $stored) {
            if (! $matched && hash_equals($normalize($stored), $input)) {
                $matched = true; // konsumsi satu kejadian; jangan masukkan ke sisa

                continue;
            }
            $remaining[] = $stored; // simpan sisa dalam format aslinya (XXXX-XXXX)
        }

        if (! $matched) {
            return false;
        }

        $this->two_factor_recovery_codes = array_values($remaining); // mutator meng-enkripsi ulang
        $this->save();

        return true;
    }
}
