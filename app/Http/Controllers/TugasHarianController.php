<?php

namespace App\Http\Controllers;

use App\Models\PeriodeKinerja;
use App\Models\TugasBukti;
use App\Models\TugasHarian;
use App\Services\ApprovalService;
use App\Services\InsentifCalculatorService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class TugasHarianController extends Controller
{
    public function __construct(
        protected InsentifCalculatorService $calculator,
        protected ApprovalService $approvalService
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $periode = PeriodeKinerja::getActive();

        $query = TugasHarian::where('user_id', $user->id)
            ->with(['bukti', 'riwayatPersetujuan.approver'])
            ->orderBy('tanggal', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('periode_id')) {
            $query->where('periode_id', $request->periode_id);
        } else {
            $query->where('periode_id', $periode->id);
        }

        if ($request->filled('kategori')) {
            $query->where('kategori', $request->kategori);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $tasks = $query->paginate(15)->withQueryString();
        $allPeriodes = PeriodeKinerja::orderBy('tahun', 'desc')->orderBy('bulan', 'desc')->get();

        $rekap = $this->calculator->calculateForUser($user, $periode);

        return Inertia::render('Tugas/Index', [
            'tasks' => $tasks,
            'currentPeriode' => $periode,
            'allPeriodes' => $allPeriodes,
            'rekap' => $rekap,
            'filters' => $request->only(['periode_id', 'kategori', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'tanggal' => 'required|date',
            'kategori' => 'required|in:utama,tambahan',
            'judul' => 'required|string|max:255',
            'deskripsi' => 'nullable|string',
            'jam_mulai' => 'nullable|string',
            'jam_selesai' => 'nullable|string',
            'durasi_jam' => 'required|numeric|min:0.25|max:24',
            'is_draft' => 'boolean',
            'foto' => 'nullable|image|max:10240',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'accuracy' => 'nullable|numeric',
        ]);

        $tgl = Carbon::parse($request->tanggal);
        $periode = PeriodeKinerja::firstOrCreate(
            ['tahun' => $tgl->year, 'bulan' => $tgl->month],
            [
                'nama' => $tgl->translatedFormat('F Y'),
                'target_jam_utama' => 150.00,
                'target_jam_tambahan' => 15.00,
                'status' => 'aktif',
            ]
        );

        $isDraft = $request->boolean('is_draft');
        $status = $isDraft ? 'draft' : 'diajukan';

        $tugas = TugasHarian::create([
            'user_id' => $request->user()->id,
            'periode_id' => $periode->id,
            'tanggal' => $request->tanggal,
            'kategori' => $request->kategori,
            'judul' => $request->judul,
            'deskripsi' => $request->deskripsi,
            'jam_mulai' => $request->jam_mulai,
            'jam_selesai' => $request->jam_selesai,
            'durasi_jam' => $request->durasi_jam,
            'jam_disetujui' => null,
            'status' => $status,
            'level_saat_ini' => $this->approvalService->initialLevelFor($request->user()),
            'tgl_pengajuan' => $isDraft ? null : now(),
        ]);

        if ($request->hasFile('foto')) {
            $file = $request->file('foto');
            $path = $file->store('bukti_tugas/' . $tgl->format('Y_m'), 'public');

            TugasBukti::create([
                'tugas_harian_id' => $tugas->id,
                'file_path' => Storage::url($path),
                'file_name' => $file->getClientOriginalName(),
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'accuracy' => $request->accuracy,
                'waktu_ambil' => now(),
            ]);
        }

        return back()->with('success', $isDraft ? 'Draft tugas harian berhasil disimpan.' : 'Tugas harian berhasil diajukan untuk verifikasi.');
    }

    public function update(Request $request, TugasHarian $tugas): RedirectResponse
    {
        if ($tugas->user_id !== $request->user()->id) {
            abort(403);
        }

        if (!in_array($tugas->status, ['draft', 'revisi'])) {
            return back()->with('error', 'Hanya tugas status draft atau revisi yang dapat diubah.');
        }

        $request->validate([
            'tanggal' => 'required|date',
            'kategori' => 'required|in:utama,tambahan',
            'judul' => 'required|string|max:255',
            'deskripsi' => 'nullable|string',
            'jam_mulai' => 'nullable|string',
            'jam_selesai' => 'nullable|string',
            'durasi_jam' => 'required|numeric|min:0.25|max:24',
            'is_draft' => 'boolean',
            'foto' => 'nullable|image|max:10240',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'accuracy' => 'nullable|numeric',
        ]);

        $isDraft = $request->boolean('is_draft');
        $status = $isDraft ? 'draft' : 'diajukan';

        $tugas->update([
            'tanggal' => $request->tanggal,
            'kategori' => $request->kategori,
            'judul' => $request->judul,
            'deskripsi' => $request->deskripsi,
            'jam_mulai' => $request->jam_mulai,
            'jam_selesai' => $request->jam_selesai,
            'durasi_jam' => $request->durasi_jam,
            'status' => $status,
            'level_saat_ini' => $this->approvalService->initialLevelFor($tugas->user ?? $request->user()),
            'alasan_tolak_revisi' => null,
            'tgl_pengajuan' => $isDraft ? $tugas->tgl_pengajuan : now(),
        ]);

        if ($request->hasFile('foto')) {
            $file = $request->file('foto');
            $path = $file->store('bukti_tugas/' . Carbon::parse($request->tanggal)->format('Y_m'), 'public');

            TugasBukti::create([
                'tugas_harian_id' => $tugas->id,
                'file_path' => Storage::url($path),
                'file_name' => $file->getClientOriginalName(),
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'accuracy' => $request->accuracy,
                'waktu_ambil' => now(),
            ]);
        }

        return back()->with('success', 'Tugas harian berhasil diperbarui.');
    }

    public function destroy(Request $request, TugasHarian $tugas): RedirectResponse
    {
        if ($tugas->user_id !== $request->user()->id) {
            abort(403);
        }

        if (!in_array($tugas->status, ['draft', 'revisi'])) {
            return back()->with('error', 'Hanya tugas draft atau revisi yang dapat dihapus.');
        }

        $tugas->delete();

        return back()->with('success', 'Tugas harian berhasil dihapus.');
    }
}
