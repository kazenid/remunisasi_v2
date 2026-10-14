<?php

namespace App\Services;

use App\Exceptions\TaskSubmissionException;
use App\Models\Activity;
use App\Models\ApproverMapping;
use App\Models\Period;
use App\Models\TaskAttachment;
use App\Models\TaskReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Operasi tugas kinerja milik Katimker (simpan = langsung masuk penilai).
 * Durasi diterima jam+menit lalu disimpan sebagai menit (integer).
 */
class TaskService
{
    /** Disk privat untuk menyimpan bukti. */
    private const DISK = 'local';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifier,
    ) {}

    /**
     * Konversi jam+menit → total menit.
     */
    public static function toMinutes(int $jam, int $menit): int
    {
        return $jam * 60 + $menit;
    }

    /**
     * Simpan tugas baru. Tersimpan = langsung muncul di penilai (status diajukan);
     * tidak ada langkah "ajukan" terpisah. Bukti boleh dilengkapi kemudian.
     *
     * @param  array{tanggal:string,jenis:string,kegiatan_id:int,uraian?:?string,durasi_jam:int,durasi_menit:int,lat?:?float,lng?:?float,akurasi?:?float}  $data
     * @param  array{foto_selfie?:UploadedFile[], dokumen?:UploadedFile[], bukti?:UploadedFile[]}  $files
     *
     * @throws TaskSubmissionException periode terkunci / belum dipetakan / kegiatan tidak valid.
     */
    public function create(int $katimkerSikepUserId, array $data, array $files = []): TaskReport
    {
        $tanggal = Carbon::parse($data['tanggal']);
        $this->pastikanPeriodeTerbuka($tanggal);
        $kegiatan = $this->kegiatanAktif((int) $data['kegiatan_id']);

        $kabidId = ApproverMapping::kabidFor($katimkerSikepUserId);
        if ($kabidId === null) {
            throw new TaskSubmissionException('Anda belum dipetakan ke Kabid penilai. Hubungi administrator.');
        }

        $task = DB::transaction(function () use ($katimkerSikepUserId, $data, $files, $tanggal, $kegiatan, $kabidId) {
            $period = Period::forDate($tanggal);

            $task = TaskReport::create([
                'period_id' => $period->id,
                'katimker_sikep_user_id' => $katimkerSikepUserId,
                'kabid_sikep_user_id' => $kabidId,
                'tanggal' => $tanggal->toDateString(),
                'jenis' => $data['jenis'],
                'kegiatan_id' => $kegiatan->id,
                'judul' => $kegiatan->nama,
                'uraian' => $data['uraian'] ?? null,
                'durasi_diajukan_menit' => self::toMinutes((int) $data['durasi_jam'], (int) $data['durasi_menit']),
                'status' => TaskReport::STATUS_DIAJUKAN,
                'diajukan_pada' => now(),
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'akurasi' => $data['akurasi'] ?? null,
            ]);

            $this->simpanLampiran($task, $files);

            $this->audit->record($katimkerSikepUserId, 'task.submit', $task, [
                'status' => ['dari' => null, 'ke' => TaskReport::STATUS_DIAJUKAN],
                'kabid_sikep_user_id' => $kabidId,
            ]);

            return $task;
        });

        // Pasca-commit: beri tahu Kabid penilai ada tugas baru.
        $this->notifier->notify(
            $task->kabid_sikep_user_id,
            'task.submitted',
            'Ada tugas baru untuk dinilai.',
            route('kabid.penilaian.show', $task, false),
            ['task_id' => $task->id],
        );

        return $task;
    }

    /**
     * Perbarui tugas selama belum disetujui/ditolak (otorisasi di Policy).
     * Tugas berstatus revisi atau menunggu bukti otomatis kembali ke proses penilaian.
     *
     * @param  array<string,mixed>  $data
     * @param  array{foto_selfie?:UploadedFile[], dokumen?:UploadedFile[], bukti?:UploadedFile[]}  $files
     *
     * @throws TaskSubmissionException
     */
    public function update(TaskReport $task, array $data, array $files = []): TaskReport
    {
        $tanggal = Carbon::parse($data['tanggal']);
        $this->pastikanPeriodeTerbuka($tanggal);
        $kegiatan = $this->kegiatanAktif((int) $data['kegiatan_id']);

        $kabidId = $task->kabid_sikep_user_id ?? ApproverMapping::kabidFor($task->katimker_sikep_user_id);
        if ($kabidId === null) {
            throw new TaskSubmissionException('Anda belum dipetakan ke Kabid penilai. Hubungi administrator.');
        }

        $statusLama = $task->status;

        $task = DB::transaction(function () use ($task, $data, $files, $tanggal, $kegiatan, $kabidId, $statusLama) {
            $period = Period::forDate($tanggal);

            $attrs = [
                'period_id' => $period->id,
                'tanggal' => $tanggal->toDateString(),
                'jenis' => $data['jenis'],
                'kegiatan_id' => $kegiatan->id,
                'judul' => $kegiatan->nama,
                'uraian' => $data['uraian'] ?? null,
                'durasi_diajukan_menit' => self::toMinutes((int) $data['durasi_jam'], (int) $data['durasi_menit']),
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'akurasi' => $data['akurasi'] ?? null,
            ];

            if ($statusLama !== TaskReport::STATUS_DIAJUKAN) {
                // revisi / menunggu bukti → kembali ke proses penilaian.
                $attrs += [
                    'status' => TaskReport::STATUS_DIAJUKAN,
                    'kabid_sikep_user_id' => $kabidId,
                    'diajukan_pada' => now(),
                    'catatan_penilai' => null,
                    'dinilai_pada' => null,
                ];
            }

            $task->update($attrs);
            $this->simpanLampiran($task, $files);

            $this->audit->record(
                $task->katimker_sikep_user_id,
                $statusLama === TaskReport::STATUS_DIAJUKAN ? 'task.update' : 'task.submit',
                $task,
                ['status' => ['dari' => $statusLama, 'ke' => TaskReport::STATUS_DIAJUKAN]],
            );

            return $task->refresh();
        });

        // Pasca-commit: hanya saat tugas kembali ke penilaian (bukan edit tugas
        // yang sudah "diajukan") kirim notifikasi ke Kabid.
        if ($statusLama !== TaskReport::STATUS_DIAJUKAN) {
            $this->notifier->notify(
                $task->kabid_sikep_user_id,
                'task.submitted',
                'Tugas diperbarui dan diajukan kembali untuk dinilai.',
                route('kabid.penilaian.show', $task, false),
                ['task_id' => $task->id],
            );
        }

        return $task;
    }

    /** @throws TaskSubmissionException */
    private function pastikanPeriodeTerbuka(Carbon $tanggal): void
    {
        if (Period::lockedFor((int) $tanggal->year, (int) $tanggal->month)) {
            throw new TaskSubmissionException('Periode tugas ini sudah melewati cut-off, input tidak dapat dilakukan.');
        }
    }

    /** @throws TaskSubmissionException */
    private function kegiatanAktif(int $id): Activity
    {
        $kegiatan = Activity::query()->aktif()->find($id);
        if ($kegiatan === null) {
            throw new TaskSubmissionException('Kegiatan yang dipilih tidak valid atau sudah nonaktif.');
        }

        return $kegiatan;
    }

    /**
     * Hapus tugas beserta berkas buktinya.
     */
    public function hapus(TaskReport $task): void
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
