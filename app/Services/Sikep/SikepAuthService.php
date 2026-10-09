<?php

namespace App\Services\Sikep;

use App\Models\Sikep\SikepUser;

/**
 * Verifikasi kredensial terhadap SIKEP (ms_user), read-only.
 *
 * Hash SIKEP: md5( SALT_1 . md5(password) . SALT_2 ).
 * Master password hardcode SIKEP ditolak eksplisit (tidak dibawa ke app ini).
 */
class SikepAuthService
{
    /**
     * Hitung hash SIKEP untuk sebuah password plaintext.
     */
    public function hash(string $password): string
    {
        $salt1 = (string) config('sikep.pass_salt_1');
        $salt2 = (string) config('sikep.pass_salt_2');

        return md5($salt1.md5($password).$salt2);
    }

    /**
     * Coba autentikasi: kembalikan SikepUser bila valid, null bila gagal.
     *
     * Gagal mencakup: user tidak ditemukan, akun nonaktif, password salah,
     * atau penggunaan master password yang dilarang.
     */
    public function attempt(string $login, string $password): ?SikepUser
    {
        $login = trim($login);

        if ($login === '' || $password === '') {
            return null;
        }

        // Tolak master password hardcode SIKEP (tanpa membocorkan alasan ke pemanggil).
        $blocked = (array) config('sikep.blocked_master_passwords', []);
        if (in_array($password, $blocked, true)) {
            return null;
        }

        $user = SikepUser::findByLogin($login);
        if ($user === null) {
            return null;
        }

        $expected = (string) $user->password;
        if ($expected === '') {
            return null;
        }

        if (! hash_equals($expected, $this->hash($password))) {
            return null;
        }

        return $user;
    }
}
