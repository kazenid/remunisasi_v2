<?php

namespace App\Services\Sikep;

use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Menentukan peran efektif pengguna dari kolom lokal `users.role` + `users.aktif`.
 *
 * Peran ditetapkan manual oleh Admin dan disimpan di DB remunisasi (lepas dari
 * jabatan lapangan SIKEP). Fail-closed: user tidak ditemukan / nonaktif /
 * tanpa peran mengembalikan peran kosong.
 */
class RoleResolver
{
    /**
     * @return array{roles: string[], is_katimker: bool, is_kabid: bool, is_admin: bool}
     */
    public function resolve(int $sikepUserId): array
    {
        $empty = ['roles' => [], 'is_katimker' => false, 'is_kabid' => false, 'is_admin' => false];

        try {
            $user = User::query()
                ->where('sikep_user_id', $sikepUserId)
                ->first(['role', 'aktif']);
        } catch (QueryException $e) {
            // Pertahanan berlapis: bila query lokal gagal tak terduga, fail-closed.
            report($e);

            return $empty;
        }

        if ($user === null || $user->aktif === false || $user->role === null) {
            return $empty;
        }

        $role = $user->role;

        return [
            'roles' => [$role],
            'is_katimker' => $role === User::ROLE_KATIMKER,
            'is_kabid' => $role === User::ROLE_KABID,
            'is_admin' => $role === User::ROLE_ADMIN,
        ];
    }
}
