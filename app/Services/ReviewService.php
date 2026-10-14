<?php

namespace App\Services;

use App\Exceptions\ReviewException;
use App\Models\TaskReport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penilaian tugas oleh Kabid (per-entri): setujui dengan jam disetujui,
 * revisi (dapat diajukan kembali), atau tolak (final). Keputusan dicatat ke audit.
 */
class ReviewService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifier,
    ) {}

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
            ->with('attachments')
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
            throw new ReviewException('Jenis hanya dapat diubah saat tugas dalam proses penilaian.');
        }

        if ($task->periodeTerkunci()) {
            throw new ReviewException('Periode tugas ini sudah melewati cut-off, penilaian tidak dapat dilakukan.');
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
     * Setujui tugas dengan jam disetujui (0..diajukan). Status menjadi final (disetujui).
     * Keterangan wajib bila jam dikurangi (disimpan juga sebagai alasan pengurangan).
     * Opsional: ubah jenis sekaligus (jenisBaru + alasanJenis).
     *
     * @throws ReviewException
     */
    public function approve(TaskReport $task, int $durasiDisetujuiMenit, ?string $keterangan, int $aktorSikepUserId, ?string $jenisBaru = null, ?string $alasanJenis = null): TaskReport
    {
        $this->pastikanDapatDinilai($task);

        if ($durasiDisetujuiMenit < 0 || $durasiDisetujuiMenit > $task->durasi_diajukan_menit) {
            throw new ReviewException('Jam disetujui harus antara 0 dan jam yang diajukan.');
        }

        $dikurangi = $durasiDisetujuiMenit < $task->durasi_diajukan_menit;
        if ($dikurangi && trim((string) $keterangan) === '') {
            throw new ReviewException('Keterangan wajib diisi bila jam disetujui lebih kecil dari yang diajukan.');
        }

        $task = DB::transaction(function () use ($task, $durasiDisetujuiMenit, $keterangan, $aktorSikepUserId, $dikurangi, $jenisBaru, $alasanJenis) {
            // Ubah jenis dulu (dalam transaksi yang sama) bila diminta.
            if ($jenisBaru !== null && $jenisBaru !== $task->jenis) {
                $this->ubahJenis($task, $jenisBaru, (string) $alasanJenis, $aktorSikepUserId);
            }

            $keterangan = trim((string) $keterangan) === '' ? null : trim((string) $keterangan);

            $task->update([
                'status' => TaskReport::STATUS_DISETUJUI,
                'durasi_disetujui_menit' => $durasiDisetujuiMenit,
                'alasan_pengurangan' => $dikurangi ? $keterangan : null,
                'catatan_penilai' => $keterangan,
                'dinilai_pada' => now(),
            ]);

            $this->audit->record($aktorSikepUserId, 'task.approve', $task, [
                'durasi_diajukan_menit' => $task->durasi_diajukan_menit,
                'durasi_disetujui_menit' => $durasiDisetujuiMenit,
                'alasan' => $dikurangi ? $keterangan : null,
            ]);

            return $task->refresh();
        });

        // Pasca-commit: beri tahu Katimker pemilik bahwa tugasnya disetujui.
        $this->notifier->notify(
            $task->katimker_sikep_user_id,
            'task.approved',
            'Tugas Anda telah disetujui.',
            route('katimker.tugas.show', $task, false),
            ['task_id' => $task->id],
        );

        return $task;
    }

    /**
     * Minta revisi: tugas dikembalikan ke Katimker dan dapat diajukan kembali.
     *
     * @throws ReviewException
     */
    public function revisi(TaskReport $task, string $keterangan, int $aktorSikepUserId): TaskReport
    {
        $task = $this->kembalikan($task, TaskReport::STATUS_REVISI, 'task.revisi', 'Keterangan revisi wajib diisi.', $keterangan, $aktorSikepUserId);

        // Pasca-commit (kembalikan() sudah commit): beri tahu Katimker pemilik.
        $this->notifier->notify(
            $task->katimker_sikep_user_id,
            'task.revisi',
            'Tugas Anda diminta untuk direvisi.',
            route('katimker.tugas.show', $task, false),
            ['task_id' => $task->id],
        );

        return $task;
    }

    /**
     * Tolak tugas: keputusan final, Katimker tidak dapat mengajukan kembali.
     *
     * @throws ReviewException
     */
    public function reject(TaskReport $task, string $keterangan, int $aktorSikepUserId): TaskReport
    {
        $task = $this->kembalikan($task, TaskReport::STATUS_DITOLAK, 'task.reject', 'Keterangan penolakan wajib diisi.', $keterangan, $aktorSikepUserId);

        // Pasca-commit (kembalikan() sudah commit): beri tahu Katimker pemilik.
        $this->notifier->notify(
            $task->katimker_sikep_user_id,
            'task.rejected',
            'Tugas Anda ditolak.',
            route('katimker.tugas.show', $task, false),
            ['task_id' => $task->id],
        );

        return $task;
    }

    private function kembalikan(TaskReport $task, string $status, string $aksi, string $pesanWajib, string $keterangan, int $aktorSikepUserId): TaskReport
    {
        $this->pastikanDapatDinilai($task);

        if (trim($keterangan) === '') {
            throw new ReviewException($pesanWajib);
        }

        return DB::transaction(function () use ($task, $status, $aksi, $keterangan, $aktorSikepUserId) {
            $task->update([
                'status' => $status,
                'catatan_penilai' => trim($keterangan),
                'dinilai_pada' => now(),
            ]);

            $this->audit->record($aktorSikepUserId, $aksi, $task, [
                'catatan' => trim($keterangan),
            ]);

            return $task->refresh();
        });
    }

    /** @throws ReviewException */
    private function pastikanDapatDinilai(TaskReport $task): void
    {
        if ($task->status !== TaskReport::STATUS_DIAJUKAN) {
            throw new ReviewException('Hanya tugas dalam proses penilaian yang dapat dinilai.');
        }

        if ($task->periodeTerkunci()) {
            throw new ReviewException('Periode tugas ini sudah melewati cut-off, penilaian tidak dapat dilakukan.');
        }
    }
}
