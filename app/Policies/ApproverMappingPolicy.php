<?php

namespace App\Policies;

use App\Models\User;

/**
 * Pemetaan approver hanya boleh dikelola oleh Admin.
 */
class ApproverMappingPolicy
{
    /**
     * Admin diizinkan untuk semua aksi pemetaan.
     */
    public function before(User $user): ?bool
    {
        return $user->hasRole('admin') ? true : null;
    }

    public function manage(User $user): bool
    {
        return false; // ditangani oleh before(); non-admin ditolak.
    }
}
