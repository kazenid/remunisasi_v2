<?php

namespace App\Http\Controllers\Katimker;

use App\Exceptions\TaskSubmissionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaskDraftRequest;
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

        return Inertia::render('Katimker/TaskList', [
            'tugas' => $tugas,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Katimker/TaskForm', [
            'tugas' => null,
        ]);
    }

    public function store(TaskDraftRequest $request): RedirectResponse
    {
        try {
            $this->service->createDraft(
                $request->user()->sikep_user_id,
                $request->validated(),
                [
                    'foto_selfie' => $request->file('foto_selfie', []),
                    'dokumen' => $request->file('dokumen', []),
                    'bukti' => $request->file('bukti', []),
                ],
            );
        } catch (\RuntimeException $e) {
            return back()
                ->withErrors(['bukti' => $e->getMessage()])
                ->with('error', 'Tugas gagal disimpan: '.$e->getMessage());
        }

        return redirect()->route('katimker.tugas.index')->with('success', 'Draf tugas disimpan.');
    }

    public function edit(TaskReport $tugas): Response
    {
        $this->authorize('view', $tugas);

        return Inertia::render('Katimker/TaskForm', [
            'tugas' => $tugas->load(['attachments' => fn ($q) => $q->orderBy('id')]),
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
            $this->service->updateDraft(
                $tugas,
                $data,
                [
                    'foto_selfie' => $request->file('foto_selfie', []),
                    'dokumen' => $request->file('dokumen', []),
                    'bukti' => $request->file('bukti', []),
                ],
            );
        } catch (\RuntimeException $e) {
            return back()
                ->withErrors(['bukti' => $e->getMessage()])
                ->with('error', 'Tugas gagal disimpan: '.$e->getMessage());
        }

        return redirect()->route('katimker.tugas.index')->with('success', 'Tugas diperbarui.');
    }

    public function destroy(TaskReport $tugas): RedirectResponse
    {
        $this->authorize('delete', $tugas);

        $this->service->deleteDraft($tugas);

        return redirect()->route('katimker.tugas.index')->with('success', 'Tugas dihapus.');
    }

    public function destroyAttachment(TaskAttachment $attachment): RedirectResponse
    {
        // Boleh menghapus lampiran bila berhak mengubah tugas induknya.
        $this->authorize('update', $attachment->taskReport);

        $this->service->hapusLampiran($attachment);

        return back()->with('success', 'Lampiran dihapus.');
    }

    public function submit(TaskReport $tugas): RedirectResponse
    {
        $this->authorize('update', $tugas); // pemilik + status editable

        try {
            $this->service->submit($tugas);
        } catch (TaskSubmissionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('katimker.tugas.index')->with('success', 'Tugas berhasil diajukan.');
    }
}
