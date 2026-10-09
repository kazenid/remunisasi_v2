<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApproverMapping;
use App\Models\Period;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class PeriodController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage', ApproverMapping::class);

        $periods = Period::query()
            ->orderByDesc('tahun')->orderByDesc('bulan')
            ->get()
            ->map(fn (Period $p) => [
                'id' => $p->id,
                'tahun' => $p->tahun,
                'bulan' => $p->bulan,
                'cut_off' => $p->cut_off?->toDateString(),
                'target_cair' => $p->target_cair?->toDateString(),
                'status' => $p->status,
            ]);

        return Inertia::render('Admin/Periode', [
            'periods' => $periods,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        $data = $request->validate([
            'tahun' => 'required|integer|min:2020|max:2100',
            'bulan' => 'required|integer|min:1|max:12',
        ]);

        $tgl = Carbon::create($data['tahun'], $data['bulan'], 1);
        Period::forDate($tgl);

        return back()->with('success', 'Periode dibuat/ditemukan.');
    }

    public function update(Request $request, Period $periode): RedirectResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        $data = $request->validate([
            'cut_off' => 'nullable|date',
            'target_cair' => 'nullable|date',
            'status' => 'required|in:terbuka,terkunci',
        ]);

        $lama = ['status' => $periode->status, 'cut_off' => $periode->cut_off?->toDateString()];

        $periode->update([
            'cut_off' => $data['cut_off'] ?? null,
            'target_cair' => $data['target_cair'] ?? null,
            'status' => $data['status'],
        ]);

        $this->audit->record($request->user()->sikep_user_id, 'period.update', $periode, [
            'periode' => "{$periode->tahun}-{$periode->bulan}",
            'dari' => $lama,
            'ke' => ['status' => $periode->status, 'cut_off' => $periode->cut_off?->toDateString()],
        ]);

        return back()->with('success', 'Periode diperbarui.');
    }
}
