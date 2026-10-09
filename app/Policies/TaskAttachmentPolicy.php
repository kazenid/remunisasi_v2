<?php

namespace App\Policies;

use App\Models\TaskAttachment;
use App\Models\User;

/**
 * Akses berkas bukti: hanya pemilik tugas (Katimker), Kabid penilai
 * yang dipetakan (snapshot pada tugas), atau Admin.
 */
class TaskAttachmentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('admin') ? true : null;
    }

    public function view(User $user, TaskAttachment $attachment): bool
    {
        $task = $attachment->taskReport;

        if ($task === null) {
            return false;
        }

        return $user->sikep_user_id === $task->katimker_sikep_user_id
            || $user->sikep_user_id === $task->kabid_sikep_user_id;
    }
}
