<?php

namespace App\Services;

use App\Exceptions\TaskSubmissionException;
use App\Models\ApproverMapping;
use App\Models\Period;
use App\Models\TaskAttachment;
use App\Models\TaskReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Operasi tugas kinerja milik Katimker (draf, pengajuan, pengajuan ulang).
 * Durasi diterima jam+menit lalu disimpan sebagai menit (integer).
 */
class TaskService
{
    /** Disk privat untuk menyimpan bukti. */
    private const DISK = 'local';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Konversi jam+menit → total menit.
     */
    public static function toMinutes(int $jam, int $menit): int
    {
        return $jam * 60 + $menit;
    }

    /**
     * Buat tugas baru berstatus draf milik Katimker.
     *
     * @param  array{tanggal:string,jenis:string,judul:string,uraian?:?string,durasi_jam:int,durasi_menit:int,lat?:?float,lng?:?float,akurasi?:?float}  $data
     * @param  array{foto_selfie?:UploadedFile[], dokumen?:UploadedFile[], bukti?:UploadedFile[]}  $files
     */
    public function createDraft(int $katimkerSikepUserId, array $data, array $files = []): TaskReport
    {
        $tanggal = Carbon::parse($data['tanggal']);

        return DB::transaction(function () use ($katimkerSikepUserId, $data, $files, $tanggal) {
            $period = Period::forDate($tanggal);

            $task = TaskReport::create([
                'period_id' => $period->id,
                'katimker_sikep_user_id' => $katimkerSikepUserId,
                'tanggal' => $tanggal->toDateString(),
                'jenis' => $data['jenis'],
                'judul' => $data['judul'],
                'uraian' => $data['uraian'] ?? null,
                'durasi_diajukan_menit' => self::toMinutes((int) $data['durasi_jam'], (int) $data['durasi_menit']),
                'status' => TaskReport::STATUS_DRAF,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'akurasi' => $data['akurasi'] ?? null,
            ]);

            $this->simpanLampiran($task, $files);

            return $task;
        });
    }

    /**
     * Perbarui tugas (hanya saat status draf atau ditolak — ditegakkan di controller/policy).
     *
     * @param  array<string,mixed>  $data
     * @param  array{foto_selfie?:UploadedFile[], dokumen?:UploadedFile[], bukti?:UploadedFile[]}  $files
     */
    public function updateDraft(TaskReport $task, array $data, array $files = []): TaskReport
    {
        return DB::transaction(function () use ($task, $data, $files) {
            $tanggal = Carbon::parse($data['tanggal']);
            $period = Period::forDate($tanggal);

            $task->update([
                'period_id' => $period->id,
                'tanggal' => $tanggal->toDateString(),
                'jenis' => $data['jenis'],
                'judul' => $data['judul'],
                'uraian' => $data['uraian'] ?? null,
                'durasi_diajukan_menit' => self::toMinutes((int) $data['durasi_jam'], (int) $data['durasi_menit']),
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'akurasi' => $data['akurasi'] ?? null,
            ]);

            $this->simpanLampiran($task, $files);

            return $task->refresh();
        });
    }

    /**
     * Hapus tugas beserta berkas buktinya.
     */
    public function deleteDraft(TaskReport $task): void
    {
        DB::transaction(function () use ($task) {
            foreach ($task->attachments as $att) {
                Storage::disk(self::DISK)->delete($att->path);
            }
            $task->delete(); // attachments ikut terhapus (cascade FK)
        });
    }

    /**
     * Hapus satu lampiran milik tugas (saat masih dapat diedit).
     */
    public function hapusLampiran(TaskAttachment $attachment): void
    {
        Storage::disk(self::DISK)->delete($attachment->path);
        $attachment->delete();
    }

    /**
     * Ajukan tugas ke Kabid (dari status draf atau ditolak → diajukan).
     *
     * Syarat: durasi > 0, minimal satu foto selfie, dan Katimker sudah
     * dipetakan ke seorang Kabid. Men-snapshot kabid penilai & waktu pengajuan.
     *
     * @throws TaskSubmissionException bila syarat tidak terpenuhi.
     */
    public function submit(TaskReport $task): TaskReport
    {
        if (! $task->isEditable()) {
            throw new TaskSubmissionException('Tugas ini tidak dapat diajukan dari statusnya saat ini.');
        }

        if ($task->durasi_diajukan_menit <= 0) {
            throw new TaskSubmissionException('Durasi harus lebih dari 0 sebelum diajukan.');
        }

        if (! $task->hasSelfie()) {
            throw new TaskSubmissionException('Wajib melampirkan minimal satu foto selfie sebelum mengajukan.');
        }

        $kabidId = ApproverMapping::kabidFor($task->katimker_sikep_user_id);
        if ($kabidId === null) {
            throw new TaskSubmissionException('Anda belum dipetakan ke Kabid penilai. Hubungi administrator.');
        }

        $statusLama = $task->status;

        return DB::transaction(function () use ($task, $kabidId, $statusLama) {
            $task->update([
                'status' => TaskReport::STATUS_DIAJUKAN,
                'kabid_sikep_user_id' => $kabidId,
                'diajukan_pada' => now(),
                // Bersihkan jejak penilaian sebelumnya bila ini pengajuan ulang.
                'catatan_penilai' => null,
                'dinilai_pada' => null,
            ]);

            $this->audit->record($task->katimker_sikep_user_id, 'task.submit', $task, [
                'status' => ['dari' => $statusLama, 'ke' => TaskReport::STATUS_DIAJUKAN],
                'kabid_sikep_user_id' => $kabidId,
            ]);

            return $task->refresh();
        });
    }

    /**
     * Simpan berkas bukti ke disk privat dan catat metadatanya.
     *
     * @param  array{foto_selfie?:UploadedFile[], dokumen?:UploadedFile[], bukti?:UploadedFile[]}  $files
     */
    private function simpanLampiran(TaskReport $task, array $files): void
    {
        $dir = "bukti/{$task->tanggal->format('Y-m')}/{$task->id}";

        foreach (($files['foto_selfie'] ?? []) as $file) {
            $this->simpanSatu($task, $file, TaskAttachment::TIPE_FOTO_SELFIE, $dir);
        }
        foreach (($files['dokumen'] ?? []) as $file) {
            $this->simpanSatu($task, $file, TaskAttachment::TIPE_DOKUMEN, $dir);
        }
        // Bukti campuran: gambar dipetakan ke foto_selfie (agar menghitung
        // hasSelfie), selain itu disimpan sebagai dokumen.
        foreach (($files['bukti'] ?? []) as $file) {
            $this->simpanSatu($task, $file, $this->tipeUntuk($file), $dir);
        }
    }

    /**
     * Petakan file campuran ke enum tipe berdasarkan MIME: gambar → foto_selfie,
     * selain itu → dokumen.
     */
    private function tipeUntuk(UploadedFile $file): string
    {
        return str_starts_with((string) $file->getMimeType(), 'image/')
            ? TaskAttachment::TIPE_FOTO_SELFIE
            : TaskAttachment::TIPE_DOKUMEN;
    }

    private function simpanSatu(TaskReport $task, UploadedFile $file, string $tipe, string $dir): void
    {
        $namaAsli = $file->getClientOriginalName();

        try {
            $path = $file->store($dir, self::DISK);

            if ($path === false) {
                throw new \RuntimeException("Gagal menyimpan file \"{$namaAsli}\" ke penyimpanan.");
            }

            $task->attachments()->create([
                'tipe' => $tipe,
                'path' => $path,
                'nama_asli' => $namaAsli,
                'mime' => $file->getClientMimeType(),
                'ukuran' => $file->getSize(),
                'diambil_pada' => now(),
            ]);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Gagal menyimpan file \"{$namaAsli}\" ke penyimpanan.", 0, $e);
        }
    }
}
