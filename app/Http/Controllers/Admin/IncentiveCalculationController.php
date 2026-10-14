<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApproverMapping;
use App\Models\IncentiveConfig;
use App\Models\IncentivePlafonOverride;
use App\Models\IncentiveResult;
use App\Services\AuditLogger;
use App\Services\IncentiveCalculatorService;
use App\Services\IncentiveReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Halaman "Hitung Insentif": simulasi perhitungan insentif per Katimker per
 * periode, pengaturan plafon override per pegawai, dan finalisasi hasil.
 */
class IncentiveCalculationController extends Controller
{
    public function __construct(private readonly IncentiveCalculatorService $calc) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage', ApproverMapping::class);

        [$tahun, $bulan] = $this->periode($request);

        $difinalkan = IncentiveResult::query()
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->exists();

        return Inertia::render('Admin/HitungInsentif', [
            'tahun' => $tahun,
            'bulan' => $bulan,
            'baris' => $this->calc->hitung($tahun, $bulan),
            'nominal_dasar' => (int) (IncentiveConfig::global()->nominal_dasar ?? 0),
            'difinalkan' => $difinalkan,
        ]);
    }

    /** Halaman laporan insentif per periode (dapat dicetak). */
    public function laporan(Request $request, IncentiveReportService $laporan): Response
    {
        $this->authorize('manage', ApproverMapping::class);

        [$tahun, $bulan] = $this->periode($request);

        return Inertia::render('Admin/LaporanInsentif', [
            'laporan' => $laporan->susun($tahun, $bulan),
        ]);
    }

    /** Unduh laporan insentif periode sebagai berkas Excel (.xlsx). */
    public function unduh(Request $request, IncentiveReportService $laporan, AuditLogger $audit): BinaryFileResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        [$tahun, $bulan] = $this->periode($request);
        $path = $laporan->buatExcel($tahun, $bulan);

        $audit->record($request->user()->sikep_user_id, 'incentive.export', null, ['periode' => sprintf('%04d-%02d', $tahun, $bulan)]);

        return response()
            ->download($path, sprintf('laporan-insentif-%04d-%02d.xlsx', $tahun, $bulan))
            ->deleteFileAfterSend(true);
    }

    public function plafon(Request $request): RedirectResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        $data = $request->validate([
            'katimker_sikep_user_id' => 'required|integer',
            'tahun' => 'required|integer|min:2000|max:2100',
            'bulan' => 'required|integer|min:1|max:12',
            'nominal' => 'required|integer|min:0',
        ]);

        IncentivePlafonOverride::updateOrCreate(
            [
                'katimker_sikep_user_id' => $data['katimker_sikep_user_id'],
                'tahun' => $data['tahun'],
                'bulan' => $data['bulan'],
            ],
            [
                'nominal' => $data['nominal'],
                'di_set_oleh' => $request->user()->sikep_user_id,
            ],
        );

        return back()->with('success', 'Plafon pegawai disimpan.');
    }

    public function finalkan(Request $request): RedirectResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        $data = $request->validate([
            'tahun' => 'required|integer|min:2000|max:2100',
            'bulan' => 'required|integer|min:1|max:12',
        ]);

        $jumlah = $this->calc->finalkan($data['tahun'], $data['bulan'], $request->user()->sikep_user_id);

        return back()->with('success', "Insentif periode {$data['bulan']}/{$data['tahun']} difinalkan ({$jumlah} pegawai).");
    }

    /**
     * Ambil tahun & bulan dari query, default periode saat ini.
     *
     * @return array{0:int,1:int}
     */
    private function periode(Request $request): array
    {
        $now = now();
        $tahun = (int) $request->integer('tahun', $now->year);
        $bulan = (int) $request->integer('bulan', $now->month);

        if ($bulan < 1 || $bulan > 12) {
            $bulan = $now->month;
        }

        return [$tahun, $bulan];
    }
}
