<?php

namespace App\Http\Controllers\Kabid;

use App\Exceptions\ReviewException;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ApproverMapping;
use App\Models\Sikep\SikepUser;
use App\Models\TaskReport;
use App\Services\AssignmentService;
use App\Services\ReviewService;
use App\Services\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $service) {}

    public function index(Request $request): Response
    {
        $kabidId = $request->user()->sikep_user_id;
        $tugas = $this->service->listForKabid($kabidId);

        // Nama Katimker untuk ditampilkan (resolusi dari SIKEP).
        $katimkerNama = $this->resolveNama($tugas->pluck('katimker_sikep_user_id'));

        return Inertia::render('Kabid/ReviewList', [
            'tugas' => $tugas->map(fn ($t) => [
                'id' => $t->id,
                'tanggal' => $t->tanggal->toDateString(),
                'judul' => $t->judul,
                'jenis' => $t->jenis,
                'durasi_diajukan_menit' => $t->durasi_diajukan_menit,
                'attachments_count' => $t->attachments_count,
                'peringatan' => $t->peringatanBukti(),
                'katimker_nama' => $katimkerNama[$t->katimker_sikep_user_id] ?? ('#'.$t->katimker_sikep_user_id),
            ]),
        ]);
    }

    public function show(TaskReport $tugas): Response
    {
        $this->authorize('reviewView', $tugas);

        $tugas->load('attachments');
        $katimker = SikepUser::find($tugas->katimker_sikep_user_id);

        return Inertia::render('Kabid/ReviewDetail', [
            'tugas' => [
                'id' => $tugas->id,
                'tanggal' => $tugas->tanggal->toDateString(),
                'jenis' => $tugas->jenis,
                'judul' => $tugas->judul,
                'uraian' => $tugas->uraian,
                'durasi_diajukan_menit' => $tugas->durasi_diajukan_menit,
                'durasi_disetujui_menit' => $tugas->durasi_disetujui_menit,
                'status' => $tugas->status,
                'dapat_dinilai' => request()->user()->can('review', $tugas),
                'terkunci' => $tugas->periodeTerkunci(),
                'peringatan' => $tugas->peringatanBukti(),
                'catatan_penilai' => $tugas->catatan_penilai,
                'lat' => $tugas->lat,
                'lng' => $tugas->lng,
                'diajukan_pada' => $tugas->diajukan_pada?->toDateTimeString(),
                'katimker_nama' => $katimker?->nama_lengkap ?? ('#'.$tugas->katimker_sikep_user_id),
                'attachments' => $tugas->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'tipe' => $a->tipe,
                    'nama_asli' => $a->nama_asli,
                    'url' => $a->url,
                    'is_image' => $a->is_image,
                    'mime' => $a->mime,
                ]),
            ],
        ]);
    }

    public function approve(Request $request, TaskReport $tugas): RedirectResponse
    {
        $this->authorize('review', $tugas);

        $data = $request->validate([
            'durasi_jam' => 'required|integer|min:0|max:24',
            'durasi_menit' => 'required|integer|min:0|max:59',
            'keterangan' => 'nullable|string',
            'jenis' => 'nullable|in:utama,tambahan',
            'alasan_jenis' => 'nullable|string',
        ]);

        $menit = TaskService::toMinutes((int) $data['durasi_jam'], (int) $data['durasi_menit']);

        try {
            $this->service->approve(
                $tugas, $menit, $data['keterangan'] ?? null,
                $request->user()->sikep_user_id,
                $data['jenis'] ?? null, $data['alasan_jenis'] ?? null,
            );
        } catch (ReviewException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('kabid.penilaian.index')->with('success', 'Tugas disetujui.');
    }

    public function revisi(Request $request, TaskReport $tugas): RedirectResponse
    {
        $this->authorize('review', $tugas);

        $data = $request->validate([
            'keterangan' => 'required|string',
        ], [
            'keterangan.required' => 'Keterangan revisi wajib diisi.',
        ]);

        try {
            $this->service->revisi($tugas, $data['keterangan'], $request->user()->sikep_user_id);
        } catch (ReviewException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('kabid.penilaian.index')->with('success', 'Tugas dikembalikan untuk direvisi.');
    }

    public function ubahJenis(Request $request, TaskReport $tugas): RedirectResponse
    {
        $this->authorize('review', $tugas);

        $data = $request->validate([
            'jenis' => 'required|in:utama,tambahan',
            'alasan' => 'required|string',
        ], [
            'alasan.required' => 'Alasan perubahan jenis wajib diisi.',
        ]);

        try {
            $this->service->ubahJenis($tugas, $data['jenis'], $data['alasan'], $request->user()->sikep_user_id);
        } catch (ReviewException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Jenis tugas diperbarui.');
    }

    public function reject(Request $request, TaskReport $tugas): RedirectResponse
    {
        $this->authorize('review', $tugas);

        $data = $request->validate([
            'keterangan' => 'required|string',
        ], [
            'keterangan.required' => 'Keterangan penolakan wajib diisi.',
        ]);

        try {
            $this->service->reject($tugas, $data['keterangan'], $request->user()->sikep_user_id);
        } catch (ReviewException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('kabid.penilaian.index')->with('success', 'Tugas ditolak.');
    }

    /** Halaman penugasan tambahan: daftar Katimker binaan + form. */
    public function assignForm(Request $request): Response
    {
        $kabidId = $request->user()->sikep_user_id;

        $katimkerIds = ApproverMapping::query()
            ->where('kabid_sikep_user_id', $kabidId)
            ->where('aktif', true)
            ->pluck('katimker_sikep_user_id');

        $nama = $this->resolveNama($katimkerIds);
        $binaan = $katimkerIds->map(fn ($id) => [
            'sikep_user_id' => $id,
            'nama' => $nama[$id] ?? ('#'.$id),
        ])->values();

        return Inertia::render('Kabid/Penugasan', [
            'binaan' => $binaan,
            'kegiatan' => Activity::query()->aktif()->orderBy('nama')->get(['id', 'nama']),
        ]);
    }

    public function assignStore(Request $request, AssignmentService $assignment): RedirectResponse
    {
        $data = $request->validate([
            'katimker_sikep_user_id' => 'required|integer',
            'tanggal' => 'required|date',
            'kegiatan_id' => ['required', 'integer', Rule::exists('activities', 'id')->where('aktif', true)],
            'uraian' => 'nullable|string',
        ], [
            'kegiatan_id.required' => 'Kegiatan wajib dipilih.',
        ]);

        try {
            $assignment->createForKatimker(
                $request->user()->sikep_user_id,
                (int) $data['katimker_sikep_user_id'],
                $data,
                aktorAdmin: false,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['katimker_sikep_user_id' => $e->getMessage()]);
        }

        return redirect()->route('kabid.penilaian.index')->with('success', 'Penugasan tambahan dibuat, menunggu bukti dari Katimker.');
    }

    /**
     * Resolusi nama lengkap dari daftar sikep_user_id.
     *
     * @return array<int,string>
     */
    private function resolveNama($ids): array
    {
        return SikepUser::query()
            ->whereIn('id', $ids->unique()->values())
            ->get()
            ->mapWithKeys(fn (SikepUser $u) => [$u->id => $u->nama_lengkap])
            ->all();
    }
}
