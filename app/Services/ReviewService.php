<?php

namespace App\Services;

use App\Exceptions\ReviewException;
use App\Models\TaskReport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penilaian tugas oleh Kabid (per-entri): menyetujui dengan jam disetujui,
 * atau menolak dengan catatan. Keputusan dicatat ke audit.
 */
class ReviewService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Daftar tugas berstatus "diajukan" yang menjadi tanggung jawab Kabid ini.
     *
     * @return Collection<int, TaskReport>
     */
    public function listForKabid(int $kabidSikepUserId): Collection
    {
        return TaskReport::query()
            ->untukKabid($kabidSikepUserId)
            ->where('status', TaskReport::STATUS_DIAJUKAN)
            ->withCount('attachments')
            ->orderBy('tanggal')
            ->orderBy('id')
            ->get();
    }

    /**
     * Ubah klasifikasi jenis tugas (utama ↔ tambahan) oleh Kabid.
     * Hanya saat status diajukan; alasan wajib; dicatat audit.
     *
     * @throws ReviewException
     */
    public function ubahJenis(TaskReport $task, string $jenisBaru, string $alasan, int $aktorSikepUserId): TaskReport
    {
        if ($task->status !== TaskReport::STATUS_DIAJUKAN) {
            throw new ReviewException('Jenis hanya dapat diubah saat tugas berstatus "diajukan".');
        }

        if (! in_array($jenisBaru, [TaskReport::JENIS_UTAMA, TaskReport::JENIS_TAMBAHAN], true)) {
            throw new ReviewException('Jenis tugas tidak valid.');
        }

        if (trim($alasan) === '') {
            throw new ReviewException('Alasan perubahan jenis wajib diisi.');
        }

        $jenisLama = $task->jenis;
        if ($jenisLama === $jenisBaru) {
            return $task; // tidak ada perubahan
        }

        return DB::transaction(function () use ($task, $jenisBaru, $jenisLama, $alasan, $aktorSikepUserId) {
            $task->update(['jenis' => $jenisBaru]);

            $this->audit->record($aktorSikepUserId, 'task.reclassify', $task, [
                'jenis' => ['dari' => $jenisLama, 'ke' => $jenisBaru],
                'alasan' => $alasan,
            ]);

            return $task->refresh();
        });
    }

    /**
     * Setujui tugas dengan jam disetujui (0..diajukan).
     * Bila dikurangi atau 0, alasan wajib. Status menjadi final (disetujui).
     * Opsional: ubah jenis sekaligus (jenisBaru + alasanJenis).
     *
     * @throws ReviewException
     */
    public function approve(TaskReport $task, int $durasiDisetujuiMenit, ?string $alasan, ?string $catatan, int $aktorSikepUserId, ?string $jenisBaru = null, ?string $alasanJenis = null): TaskReport
    {
        if ($task->status !== TaskReport::STATUS_DIAJUKAN) {
            throw new ReviewException('Hanya tugas berstatus "diajukan" yang dapat dinilai.');
        }

        // Ubah jenis dulu (dalam transaksi yang sama) bila diminta.
        if ($jenisBaru !== null && $jenisBaru !== $task->jenis) {
            $this->ubahJenis($task, $jenisBaru, (string) $alasanJenis, $aktorSikepUserId);
        }

        if ($durasiDisetujuiMenit < 0 || $durasiDisetujuiMenit > $task->durasi_diajukan_menit) {
            throw new ReviewException('Jam disetujui harus antara 0 dan jam yang diajukan.');
        }

        $dikurangi = $durasiDisetujuiMenit < $task->durasi_diajukan_menit;
        if ($dikurangi && trim((string) $alasan) === '') {
            throw new ReviewException('Alasan wajib diisi bila jam disetujui lebih kecil dari yang diajukan.');
        }

        return DB::transaction(function () use ($task, $durasiDisetujuiMenit, $alasan, $catatan, $aktorSikepUserId, $dikurangi) {
            $task->update([
                'status' => TaskReport::STATUS_DISETUJUI,
                'durasi_disetujui_menit' => $durasiDisetujuiMenit,
                'alasan_pengurangan' => $dikurangi ? $alasan : null,
                'catatan_penilai' => $catatan,
                'dinilai_pada' => now(),
            ]);

            $this->audit->record($aktorSikepUserId, 'task.approve', $task, [
                'durasi_diajukan_menit' => $task->durasi_diajukan_menit,
                'durasi_disetujui_menit' => $durasiDisetujuiMenit,
                'alasan' => $dikurangi ? $alasan : null,
            ]);

            return $task->refresh();
        });
    }

    /**
     * Tolak tugas dengan catatan wajib. Status menjadi ditolak
     * (Katimker dapat merevisi dan mengajukan ulang).
     *
     * @throws ReviewException
     */
    public function reject(TaskReport $task, string $catatan, int $aktorSikepUserId): TaskReport
    {
        if ($task->status !== TaskReport::STATUS_DIAJUKAN) {
            throw new ReviewException('Hanya tugas berstatus "diajukan" yang dapat dinilai.');
        }

        if (trim($catatan) === '') {
            throw new ReviewException('Catatan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($task, $catatan, $aktorSikepUserId) {
            $task->update([
                'status' => TaskReport::STATUS_DITOLAK,
                'catatan_penilai' => $catatan,
                'dinilai_pada' => now(),
            ]);

            $this->audit->record($aktorSikepUserId, 'task.reject', $task, [
                'catatan' => $catatan,
            ]);

            return $task->refresh();
        });
    }
}
