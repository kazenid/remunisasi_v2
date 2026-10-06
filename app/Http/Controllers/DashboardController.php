<?php

namespace App\Http\Controllers;

use App\Models\PeriodeKinerja;
use App\Models\RekapInsentif;
use App\Models\TugasHarian;
use App\Services\InsentifCalculatorService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        protected InsentifCalculatorService $calculator
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $periode = PeriodeKinerja::getActive();

        // Ensure monthly recap is updated
        $rekap = $this->calculator->calculateForUser($user, $periode);

        // Recent tasks
        $recentTasks = TugasHarian::where('user_id', $user->id)
            ->where('periode_id', $periode->id)
            ->with('bukti')
            ->orderBy('tanggal', 'desc')
            ->orderBy('id', 'desc')
            ->take(5)
            ->get();

        // Pending approvals count if user has approval authority
        $pendingApprovalsCount = 0;
        $isAtasanKatimker = $user->hasKatimkerStaff();

        if ($user->isKatker() || $user->isKabid() || $user->isWadir() || $user->isDirektur() || $isAtasanKatimker) {
            $query = TugasHarian::query()
                ->where('periode_id', $periode->id)
                ->whereNotIn('status', [
                    'draft',
                    'revisi',
                    'ditolak',
                    'disetujui_direktur',
                    'disetujui_atasan_katimker',
                ]);

            $levelFilters = [];

            if ($user->role === 'katker' || $user->role === 'kepala_unit') {
                $levelFilters[] = function ($q) use ($user) {
                    $q->where('level_saat_ini', 'katker');
                    if ($user->id_unit) {
                        $q->whereHas('user', fn ($u) => $u->where('id_unit', $user->id_unit));
                    }
                };
            } elseif ($user->role === 'kabid') {
                $levelFilters[] = fn ($q) => $q->where('level_saat_ini', 'kabid');
            } elseif ($user->role === 'wadir') {
                $levelFilters[] = fn ($q) => $q->where('level_saat_ini', 'wadir');
            } elseif ($user->role === 'direktur' || $user->role === 'admin') {
                $levelFilters[] = fn ($q) => $q->where('level_saat_ini', 'direktur');
            }

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

            $pendingApprovalsCount = $query->count();
        }

        return Inertia::render('Dashboard', [
            'periode' => $periode,
            'rekap' => $rekap,
            'recentTasks' => $recentTasks,
            'pendingApprovalsCount' => $pendingApprovalsCount,
            'isKatimker' => $user->isKatimker(),
            'atasanLangsung' => $user->isKatimker() ? $user->atasanLangsung?->only(['id', 'name', 'nama_lengkap']) : null,
        ]);
    }
}

