<?php

namespace App\Http\Controllers;

use App\Models\ApproverMapping;
use App\Models\Sikep\SikepUser;
use App\Services\ApproverMappingService;
use App\Services\AttendanceService;
use App\Services\RecapService;
use App\Services\TargetResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecapController extends Controller
{
    public function __construct(private readonly RecapService $recap) {}

    /** Rekap milik Katimker yang sedang login. */
    public function katimker(Request $request, AttendanceService $attendance, TargetResolver $target): Response
    {
        [$tahun, $bulan] = $this->periode($request);
        $user = $request->user();
        $id = $user->sikep_user_id;

        $data = $this->recap->forPeriod($id, $tahun, $bulan);

        $kehadiran = $user->no_absen
            ? $attendance->nilai($id, (int) $user->no_absen, $tahun, $bulan)
            : ['tersedia' => false];

        return Inertia::render('Rekap/Index', [
            'konteks' => 'katimker',
            'tahun' => $tahun,
            'bulan' => $bulan,
            'ringkasan' => $data,
            'kehadiran' => $kehadiran,
            'target' => $target->efektif($id, $tahun, $bulan),
            'baris' => [],
        ]);
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
