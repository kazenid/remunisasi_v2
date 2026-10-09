<?php

namespace App\Policies;

use App\Models\TaskReport;
use App\Models\User;

/**
 * Otorisasi tugas: Katimker hanya atas tugas miliknya.
 * (Kewenangan Kabid untuk menilai ditangani ReviewPolicy terpisah pada Tugas 8.)
 */
class TaskReportPolicy
{
    private function pemilik(User $user, TaskReport $task): bool
    {
        return $user->sikep_user_id === $task->katimker_sikep_user_id;
    }

    public function view(User $user, TaskReport $task): bool
    {
        return $this->pemilik($user, $task);
    }

    /** Ubah hanya saat tugas masih dapat diedit (draf / ditolak). */
    public function update(User $user, TaskReport $task): bool
    {
        return $this->pemilik($user, $task) && $task->isEditable();
    }

    /** Hapus hanya oleh pemilik, saat masih draf. */
    public function delete(User $user, TaskReport $task): bool
    {
        return $this->pemilik($user, $task) && $task->isDraf();
    }

    /**
     * Kabid boleh melihat tugas yang dipetakan kepadanya (snapshot kabid).
     */
    public function reviewView(User $user, TaskReport $task): bool
    {
        return $user->sikep_user_id === $task->kabid_sikep_user_id;
    }

    /**
     * Kabid boleh menilai hanya tugas miliknya (snapshot) dan berstatus diajukan.
     */
    public function review(User $user, TaskReport $task): bool
    {
        return $user->sikep_user_id === $task->kabid_sikep_user_id
            && $task->isDiajukan();
    }
}
