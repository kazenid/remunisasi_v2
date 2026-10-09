<?php

namespace App\Services;

use App\Models\ApproverMapping;
use App\Models\Period;
use App\Models\TaskReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Penugasan tugas TAMBAHAN oleh Kabid (atau Admin) kepada Katimker.
 * Tugas dibuat berjenis tambahan & terkunci, berstatus menunggu bukti;
 * Katimker melengkapi bukti + durasi lalu mengajukan.
 */
class AssignmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{tanggal:string, judul:string, uraian?:?string}  $data
     *
     * @throws InvalidArgumentException bila aktor tidak berwenang atas Katimker.
     */
    public function createForKatimker(int $aktorSikepUserId, int $katimkerSikepUserId, array $data, bool $aktorAdmin = false): TaskReport
    {
        // Hanya Kabid pembina Katimker tsb, atau Admin, yang boleh menugaskan.
        $kabidPembina = ApproverMapping::kabidFor($katimkerSikepUserId);
        if (! $aktorAdmin && $kabidPembina !== $aktorSikepUserId) {
            throw new InvalidArgumentException('Anda bukan Kabid pembina Katimker ini.');
        }

        $tanggal = Carbon::parse($data['tanggal']);

        return DB::transaction(function () use ($aktorSikepUserId, $katimkerSikepUserId, $data, $tanggal, $kabidPembina) {
            $period = Period::forDate($tanggal);

            $task = TaskReport::create([
                'period_id' => $period->id,
                'katimker_sikep_user_id' => $katimkerSikepUserId,
                // Snapshot penilai = Kabid pembina (agar tugas kembali ke Kabid yang tepat).
                'kabid_sikep_user_id' => $kabidPembina,
                'dibuat_oleh_sikep_user_id' => $aktorSikepUserId,
                'jenis_terkunci' => true,
                'tanggal' => $tanggal->toDateString(),
                'jenis' => TaskReport::JENIS_TAMBAHAN,
                'judul' => $data['judul'],
                'uraian' => $data['uraian'] ?? null,
                'durasi_diajukan_menit' => 0, // diisi Katimker
                'status' => TaskReport::STATUS_MENUNGGU_BUKTI,
            ]);

            $this->audit->record($aktorSikepUserId, 'task.assign', $task, [
                'katimker_sikep_user_id' => $katimkerSikepUserId,
                'jenis' => TaskReport::JENIS_TAMBAHAN,
            ]);

            return $task;
        });
    }
}
