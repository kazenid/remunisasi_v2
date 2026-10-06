<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Hanya admin/direktur yang boleh mengelola penugasan Katimker & atasan langsung.
     */
    public function manageKatimker(User $user): bool
    {
        return in_array($user->role, ['admin', 'direktur'], true);
    }

    /**
     * Boleh melihat daftar pegawai untuk keperluan pemilihan atasan langsung.
     */
    public function viewAny(User $user): bool
    {
        return $this->manageKatimker($user);
    }
}
