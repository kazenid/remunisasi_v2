<?php

namespace App\Http\Controllers;

use App\Models\PeriodeKinerja;
use App\Models\TugasHarian;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalController extends Controller
{
    public function __construct(
        protected ApprovalService $approvalService
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $isApprover = $user->isKatker() || $user->isKabid() || $user->isWadir() || $user->isDirektur();
        $isAtasanKatimker = $user->hasKatimkerStaff();

        if (!$isApprover && !$isAtasanKatimker) {
            abort(403, 'Anda tidak memiliki hak akses verifikasi kinerja.');
        }

        $periode = PeriodeKinerja::getActive();

        $query = TugasHarian::with(['user', 'bukti', 'riwayatPersetujuan.approver'])
            ->where('periode_id', $periode->id)
            ->whereNotIn('status', [
                'draft',
                'disetujui_direktur',
                'disetujui_atasan_katimker',
            ]);

        // Determine which level(s) to show based on role
        $levelFilters = [];

        if ($user->role === 'katker' || $user->role === 'kepala_unit') {
            $levelFilters[] = function ($q) use ($user) {
                $q->where('level_saat_ini', 'katker');
                if ($user->id_unit) {
                    $q->whereHas('user', fn($u) => $u->where('id_unit', $user->id_unit));
                }
            };
        } elseif ($user->role === 'kabid') {
            $levelFilters[] = fn ($q) => $q->where('level_saat_ini', 'kabid');
        } elseif ($user->role === 'wadir') {
            $levelFilters[] = fn ($q) => $q->where('level_saat_ini', 'wadir');
        } elseif ($user->role === 'direktur' || $user->role === 'admin') {
            $levelFilters[] = fn ($q) => $q->where('level_saat_ini', 'direktur');
        }

        // Antrean tambahan: tugas milik Katimker yang atasan langsungnya user ini.
        if ($isAtasanKatimker) {
            $levelFilters[] = function ($q) use ($user) {
                $q->where('level_saat_ini', 'atasan_katimker')
                  ->whereHas('user', fn ($u) => $u->where('atasan_langsung_id', $user->id));
            };
        }

        if (empty($levelFilters)) {
            $query->whereRaw('1=0');
        } else {
            $query->where(function ($q) use ($levelFilters) {
                foreach ($levelFilters as $index => $filter) {
                    $index === 0 ? $q->where($filter) : $q->orWhere($filter);
                }
            });
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('judul', 'like', '%' . $request->search . '%')
                  ->orWhereHas('user', fn($u) => $u->where('name', 'like', '%' . $request->search . '%'));
            });
        }

        $pendingTasks = $query->orderBy('tanggal', 'desc')->paginate(20)->withQueryString();

        return Inertia::render('Approval/Index', [
            'tasks' => $pendingTasks,
            'currentPeriode' => $periode,
            'userRole' => $user->role,
            'isAtasanKatimker' => $isAtasanKatimker,
            'filters' => $request->only(['search']),
        ]);
    }

    public function approve(Request $request, TugasHarian $tugas): RedirectResponse
    {
        $this->authorizeApproval($request, $tugas);

        $request->validate([
            'jam_disetujui' => 'nullable|numeric|min:0.25|max:24',
            'kategori_baru' => 'nullable|in:utama,tambahan',
            'catatan' => 'nullable|string|max:500',
        ]);

        $this->approvalService->process(
            $tugas,
            $request->user(),
            $request->filled('kategori_baru') ? 'perubahan_kategori' : 'setuju',
            $request->jam_disetujui,
            $request->kategori_baru,
            $request->catatan
        );

        return back()->with('success', 'Tugas berhasil diverifikasi dan diteruskan ke tahap berikutnya.');
    }

    public function revisi(Request $request, TugasHarian $tugas): RedirectResponse
    {
        $this->authorizeApproval($request, $tugas);

        $request->validate([
            'catatan' => 'required|string|max:500',
        ]);

        $this->approvalService->process(
            $tugas,
            $request->user(),
            'revisi',
            null,
            null,
            $request->catatan
        );

        return back()->with('success', 'Permintaan revisi berhasil dikirim ke pegawai.');
    }

    public function tolak(Request $request, TugasHarian $tugas): RedirectResponse
    {
        $this->authorizeApproval($request, $tugas);

        $request->validate([
            'catatan' => 'required|string|max:500',
        ]);

        $this->approvalService->process(
            $tugas,
            $request->user(),
            'tolak',
            null,
            null,
            $request->catatan
        );

        return back()->with('success', 'Tugas kinerja berhasil ditolak.');
    }

    public function bulkApprove(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:tugas_harian,id',
        ]);

        $tasks = TugasHarian::with('user')->whereIn('id', $request->ids)->get();

        $approved = 0;
        foreach ($tasks as $task) {
            if (!$this->canApprove($request->user(), $task)) {
                continue;
            }

            $this->approvalService->process(
                $task,
                $request->user(),
                'setuju',
                $task->durasi_jam,
                null,
                'Disetujui massal'
            );
            $approved++;
        }

        if ($approved === 0) {
            return back()->with('error', 'Tidak ada tugas yang dapat Anda verifikasi.');
        }

        return back()->with('success', $approved . ' tugas berhasil disetujui sekaligus.');
    }

    /**
     * Pastikan approver berhak memverifikasi tugas pada tahap saat ini.
     */
    protected function authorizeApproval(Request $request, TugasHarian $tugas): void
    {
        if (!$this->canApprove($request->user(), $tugas)) {
            abort(403, 'Anda tidak berhak memverifikasi tugas ini.');
        }
    }

    protected function canApprove(User $user, TugasHarian $tugas): bool
    {
        $tugas->loadMissing('user');
        $pemilik = $tugas->user;

        if (!$pemilik) {
            return false;
        }

        // Alur Katimker: hanya atasan langsung yang ditunjuk.
        if ($tugas->level_saat_ini === 'atasan_katimker') {
            return $user->isAtasanKatimker()
                && (int) $pemilik->atasan_langsung_id === (int) $user->id;
        }

        // Alur 4 tingkat: approver harus minimal setingkat dengan level saat ini.
        return match ($tugas->level_saat_ini) {
            'katker' => $user->isKatker(),
            'kabid' => $user->isKabid(),
            'wadir' => $user->isWadir(),
            'direktur' => $user->isDirektur(),
            default => false,
        };
    }
}
