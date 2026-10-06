<?php

namespace App\Http\Controllers;

use App\Models\PeriodeKinerja;
use App\Models\RekapInsentif;
use App\Models\User;
use App\Services\InsentifCalculatorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RekapInsentifController extends Controller
{
    public function __construct(
        protected InsentifCalculatorService $calculator
    ) {}

    public function index(Request $request): Response
    {
        $periode = $request->filled('periode_id')
            ? PeriodeKinerja::findOrFail($request->periode_id)
            : PeriodeKinerja::getActive();

        $query = RekapInsentif::with('user')
            ->where('periode_id', $periode->id);

        if ($request->filled('search')) {
            $query->whereHas('user', function ($u) use ($request) {
                $u->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('nip', 'like', '%' . $request->search . '%')
                  ->orWhere('nama_unit', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('unit')) {
            $query->whereHas('user', fn($u) => $u->where('nama_unit', $request->unit));
        }

        $rekaps = $query->orderBy('total_persen_kinerja', 'desc')->paginate(25)->withQueryString();
        $allPeriodes = PeriodeKinerja::orderBy('tahun', 'desc')->orderBy('bulan', 'desc')->get();
        $allUnits = User::whereNotNull('nama_unit')->distinct()->pluck('nama_unit');

        // Totals
        $totalNominal = RekapInsentif::where('periode_id', $periode->id)->sum('nominal_insentif');
        $totalPegawai = RekapInsentif::where('periode_id', $periode->id)->count();
        $avgPersen = RekapInsentif::where('periode_id', $periode->id)->avg('total_persen_kinerja') ?? 0;

        return Inertia::render('Rekap/Index', [
            'rekaps' => $rekaps,
            'currentPeriode' => $periode,
            'allPeriodes' => $allPeriodes,
            'allUnits' => $allUnits,
            'stats' => [
                'totalNominal' => (float) $totalNominal,
                'totalPegawai' => (int) $totalPegawai,
                'avgPersen' => round((float) $avgPersen, 2),
            ],
            'filters' => $request->only(['periode_id', 'search', 'unit']),
        ]);
    }

    public function calculate(Request $request, PeriodeKinerja $periode): RedirectResponse
    {
        $users = User::where('is_active', true)->get();
        foreach ($users as $user) {
            $this->calculator->calculateForUser($user, $periode);
        }

        return back()->with('success', 'Rekapitulasi insentif periode ' . $periode->nama . ' berhasil dihitung ulang.');
    }
}
