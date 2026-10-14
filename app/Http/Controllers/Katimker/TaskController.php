<?php

namespace App\Http\Controllers\Katimker;

use App\Exceptions\TaskSubmissionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaskDraftRequest;
use App\Models\Activity;
use App\Models\TaskAttachment;
use App\Models\TaskReport;
use App\Services\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $service) {}

    public function index(Request $request): Response
    {
        $sikepUserId = $request->user()->sikep_user_id;

        $tugas = TaskReport::query()
            ->milik($sikepUserId)
            ->withCount('attachments')
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->get();

        $terkunci = [];
        $tugas->transform(function (TaskReport $t) use (&$terkunci) {
            $kunci = $t->tanggal->format('Y-m');
            $terkunci[$kunci] ??= $t->periodeTerkunci();

            $t->setAttribute('periode_terkunci', $terkunci[$kunci]);
            $t->setAttribute('dapat_diubah', $t->isEditable() && ! $terkunci[$kunci]);
            $t->setAttribute('dapat_dihapus', $t->isEditable() && ! $terkunci[$kunci]
                && ! $t->isMenungguBukti() && $t->dibuat_oleh_sikep_user_id === null);

            return $t;
        });

        return Inertia::render('Katimker/TaskList', [
            'tugas' => $tugas,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Katimker/TaskForm', [
            'tugas' => null,
            'kegiatan' => $this->kegiatanOptions(),
        ]);
    }

    public function store(TaskDraftRequest $request): RedirectResponse
    {
        try {
            $this->service->create(
                $request->user()->sikep_user_id,
                $request->validated(),
                [
                    'foto_selfie' => $request->file('foto_selfie', []),
                    'dokumen' => $request->file('dokumen', []),
                    'bukti' => $request->file('bukti', []),
                ],
            );
        } catch (TaskSubmissionException $e) {
            return back()
                ->withErrors(['form' => $e->getMessage()])
                ->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()
                ->withErrors(['bukti' => $e->getMessage()])
                ->with('error', 'Tugas gagal disimpan: '.$e->getMessage());
        }

        return redirect()->route('katimker.tugas.index')->with('success', 'Tugas disimpan dan masuk ke penilai.');
    }

    /** Rincian tugas (baca-saja), termasuk yang sudah disetujui/ditolak. */
    public function show(TaskReport $tugas): Response
    {
        $this->authorize('view', $tugas);

        $tugas->load(['attachments' => fn ($q) => $q->orderBy('id')]);

        return Inertia::render('Katimker/TaskDetail', [
            'tugas' => [
                'id' => $tugas->id,
                'tanggal' => $tugas->tanggal->toDateString(),
                'jenis' => $tugas->jenis,
                'judul' => $tugas->judul,
                'uraian' => $tugas->uraian,
                'status' => $tugas->status,
                'durasi_diajukan_menit' => $tugas->durasi_diajukan_menit,
                'durasi_disetujui_menit' => $tugas->durasi_disetujui_menit,
                'alasan_pengurangan' => $tugas->alasan_pengurangan,
                'catatan_penilai' => $tugas->catatan_penilai,
                'lat' => $tugas->lat,
                'lng' => $tugas->lng,
                'diajukan_pada' => $tugas->diajukan_pada?->toDateTimeString(),
                'dinilai_pada' => $tugas->dinilai_pada?->toDateTimeString(),
                'penugasan' => $tugas->dibuat_oleh_sikep_user_id !== null,
                'dapat_diubah' => request()->user()->can('update', $tugas),
                'attachments' => $tugas->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'tipe' => $a->tipe,
                    'nama_asli' => $a->nama_asli,
                    'url' => $a->url,
                    'is_image' => $a->is_image,
                ]),
            ],
        ]);
    }

    public function edit(TaskReport $tugas): Response
    {
        $this->authorize('view', $tugas);

        return Inertia::render('Katimker/TaskForm', [
            'tugas' => $tugas->load(['attachments' => fn ($q) => $q->orderBy('id')]),
            'kegiatan' => $this->kegiatanOptions($tugas->kegiatan_id),
        ]);
    }

    public function update(TaskDraftRequest $request, TaskReport $tugas): RedirectResponse
    {
        $this->authorize('update', $tugas);

        $data = $request->validated();
        // Penugasan Kabid: jenis terkunci, Katimker tidak boleh mengubahnya.
        if ($tugas->jenis_terkunci) {
            $data['jenis'] = $tugas->jenis;
        }

        try {
            $this->service->update(
                $tugas,
                $data,
                [
                    'foto_selfie' => $request->file('foto_selfie', []),
                    'dokumen' => $request->file('dokumen', []),
                    'bukti' => $request->file('bukti', []),
                ],
            );
        } catch (TaskSubmissionException $e) {
            return back()
                ->withErrors(['form' => $e->getMessage()])
                ->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()
                ->withErrors(['bukti' => $e->getMessage()])
                ->with('error', 'Tugas gagal disimpan: '.$e->getMessage());
        }

        return redirect()->route('katimker.tugas.index')->with('success', 'Tugas diperbarui dan masuk ke penilai.');
    }

    public function destroy(TaskReport $tugas): RedirectResponse
    {
        $this->authorize('delete', $tugas);

        $this->service->hapus($tugas);

        return redirect()->route('katimker.tugas.index')->with('success', 'Tugas dihapus.');
    }

    public function destroyAttachment(TaskAttachment $attachment): RedirectResponse
    {
        // Boleh menghapus lampiran bila berhak mengubah tugas induknya.
        $this->authorize('update', $attachment->taskReport);

        $this->service->hapusLampiran($attachment);

        return back()->with('success', 'Lampiran dihapus.');
    }

    /**
     * Pilihan kegiatan aktif (plus kegiatan tugas ini bila sudah dinonaktifkan,
     * agar pilihan lama tetap tampil saat mengedit).
     *
     * @return array<int,array{id:int,nama:string}>
     */
    private function kegiatanOptions(?int $termasukId = null): array
    {
        return Activity::query()
            ->where(fn ($q) => $q->where('aktif', true)->when($termasukId, fn ($q2) => $q2->orWhere('id', $termasukId)))
            ->orderBy('nama')
            ->get(['id', 'nama'])
            ->all();
    }
}
