<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Pencatat audit append-only untuk peristiwa penting
 * (perubahan status tugas, keputusan penilaian, perubahan pemetaan approver).
 */
class AuditLogger
{
    /**
     * Catat satu peristiwa audit.
     *
     * @param  int|null  $aktorSikepUserId  sikep_user_id pelaku (null bila sistem)
     * @param  string  $aksi  mis. "task.submit", "task.approve", "mapping.update"
     * @param  Model|null  $subject  entitas terkait (TaskReport, ApproverMapping, …)
     * @param  array<string,mixed>  $meta  detail: status lama→baru, nilai, alasan
     */
    public function record(?int $aktorSikepUserId, string $aksi, ?Model $subject = null, array $meta = []): AuditLog
    {
        return AuditLog::create([
            'aktor_sikep_user_id' => $aktorSikepUserId,
            'aksi' => $aksi,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta !== [] ? $meta : null,
        ]);
    }
}
