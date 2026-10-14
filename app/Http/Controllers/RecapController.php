<?php

namespace App\Http\Controllers;

use App\Models\ApproverMapping;
use App\Models\Period;
use App\Models\Sikep\SikepUser;
use App\Models\TaskReport;
use App\Services\ApproverMappingService;
use App\Services\AttendanceService;
use App\Services\IncentiveReportService;
use App\Services\IncentiveStatementService;
use App\Services\RecapService;
use App\Services\TargetResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RecapController extends Controller
{
    public function __construct(private readonly RecapService $recap) {}

    /** Rekap milik Katimker yang sedang login: capaian, perkiraan/penetapan insentif, dan rincian tugas. */
    public function katimker(Request $request, AttendanceService $attendance, TargetResolver $target, IncentiveStatementService $statement): Response
    {
        [$tahun, $bulan] = $this->periode($request);
        $user = $request->user();
        $id = $user->sikep_user_id;

        $kehadiran = ['tersedia' => false];
        if ($user->no_absen) {
            try {
                $kehadiran = $attendance->nilai($id, (int) $user->no_absen, $tahun, $bulan);
            } catch (Throwable) {
                // SIKEP tak terjangkau: halaman tetap tampil tanpa data kehadiran.
            }
        }

        $tugas = TaskReport::query()
            ->milik($id)
            ->periode($tahun, $bulan)
            ->withCount('attachments')
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->get();

        $ringkas = [];
        foreach ([TaskReport::STATUS_DISETUJUI, TaskReport::STATUS_DIAJUKAN, TaskReport::STATUS_REVISI, TaskReport::STATUS_MENUNGGU_BUKTI, TaskReport::STATUS_DITOLAK] as $st) {
            $grup = $tugas->where('status', $st);
            $ringkas[$st] = [
                'jumlah' => $grup->count(),
                'menit' => (int) ($st === TaskReport::STATUS_DISETUJUI ? $grup->sum('durasi_disetujui_menit') : $grup->sum('durasi_diajukan_menit')),
            ];
        }

        return Inertia::render('Rekap/Katimker', [
            'tahun' => $tahun,
            'bulan' => $bulan,
            'periode' => Period::info($tahun, $bulan),
            'ringkasan' => $this->recap->forPeriod($id, $tahun, $bulan),
            'kehadiran' => $kehadiran,
            'target' => $target->efektif($id, $tahun, $bulan),
            'insentif' => $statement->untuk($user, $tahun, $bulan),
            'ringkas_status' => $ringkas,
            'tugas' => $tugas->map(fn (TaskReport $t) => [
                'id' => $t->id,
                'tanggal' => $t->tanggal->toDateString(),
                'judul' => $t->judul,
                'jenis' => $t->jenis,
                'status' => $t->status,
                'durasi_diajukan_menit' => $t->durasi_diajukan_menit,
                'durasi_disetujui_menit' => $t->durasi_disetujui_menit,
            ])->values(),
        ]);
    }

    /** Unduh slip insentif periode (PDF), berstatus Final atau Estimasi. */
    public function slip(Request $request, IncentiveStatementService $statement)
    {
        [$tahun, $bulan] = $this->periode($request);
        $user = $request->user();

        $d = $statement->untuk($user, $tahun, $bulan);

        $logo = $this->logoSlip();

        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $pct = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',').'%';
        $durasi = function (?int $menit) {
            $menit = (int) $menit;
            $j = intdiv($menit, 60);
            $m = $menit % 60;

            return trim(($j ? $j.' jam ' : '').($m ? $m.' menit' : '')) ?: '0 menit';
        };

        $pdf = Pdf::loadView('pdf.slip-insentif', [
            'd' => $d,
            'nama' => $user->nama,
            'periode' => IncentiveReportService::BULAN[$bulan].' '.$tahun,
            'difinalkan' => $d['difinalkan_pada'] ? Carbon::parse($d['difinalkan_pada'])->timezone(Period::TZ)->format('d/m/Y H:i') : null,
            'dicetak' => now(Period::TZ)->format('d/m/Y H:i'),
            'logo' => $logo,
            'rp' => $rp,
            'pct' => $pct,
            'durasi' => $durasi,
        ])->setPaper('a4');

        return $pdf->download(sprintf('slip-insentif-%04d-%02d%s.pdf', $tahun, $bulan, $d['status'] === 'final' ? '' : '-estimasi'));
    }

    /**
     * Logo untuk slip, diperkecil (logo asli 2000px+ membuat DomPDF kehabisan memori)
     * dan di-cache di storage. Null bila logo/GD tidak tersedia.
     */
    private function logoSlip(): ?string
    {
        $sumber = public_path('images/logo_rsud_bangil.png');
        $cache = storage_path('app/slip-logo.png');

        if (! is_file($cache) && is_file($sumber) && function_exists('imagecreatefrompng')) {
            $img = @imagecreatefrompng($sumber);
            if ($img !== false) {
                $kecil = imagescale($img, 160);
                if ($kecil !== false) {
                    imagealphablending($kecil, false);
                    imagesavealpha($kecil, true);
                    imagepng($kecil, $cache, 9);
                }
            }
        }

        return is_file($cache) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($cache)) : null;
    }

    /** Rekap untuk Katimker binaan Kabid yang login. */
    public function kabid(Request $request): Response
    {
        [$tahun, $bulan] = $this->periode($request);
        $kabidId = $request->user()->sikep_user_id;

        $katimkerIds = ApproverMapping::query()
            ->where('kabid_sikep_user_id', $kabidId)
            ->where('aktif', true)
            ->pluck('katimker_sikep_user_id');

        return Inertia::render('Rekap/Index', [
            'konteks' => 'kabid',
            'tahun' => $tahun,
            'bulan' => $bulan,
            'ringkasan' => null,
            'baris' => $this->barisDenganNama($katimkerIds, $tahun, $bulan),
        ]);
    }

    /** Rekap lintas seluruh Katimker (Admin). */
    public function admin(Request $request, ApproverMappingService $mappingService): Response
    {
        [$tahun, $bulan] = $this->periode($request);

        $katimkerIds = $mappingService->listKatimker()->pluck('sikep_user_id');

        return Inertia::render('Rekap/Index', [
            'konteks' => 'admin',
            'tahun' => $tahun,
            'bulan' => $bulan,
            'ringkasan' => null,
            'baris' => $this->barisDenganNama($katimkerIds, $tahun, $bulan),
        ]);
    }

    /**
     * Bangun baris rekap + nama Katimker dari SIKEP.
     */
    private function barisDenganNama($katimkerIds, int $tahun, int $bulan): array
    {
        $rekap = $this->recap->forKatimkerList($katimkerIds, $tahun, $bulan);

        $nama = SikepUser::query()
            ->whereIn('id', $rekap->pluck('katimker_sikep_user_id'))
            ->get()
            ->mapWithKeys(fn (SikepUser $u) => [$u->id => $u->nama_lengkap]);

        return $rekap->map(fn ($r) => array_merge($r, [
            'nama' => $nama[$r['katimker_sikep_user_id']] ?? ('#'.$r['katimker_sikep_user_id']),
        ]))->all();
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
