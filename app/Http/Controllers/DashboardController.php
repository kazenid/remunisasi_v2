<?php

namespace App\Http\Controllers;

use App\Models\ApproverMapping;
use App\Models\AuditLog;
use App\Models\IncentiveResult;
use App\Models\Period;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\ApproverMappingService;
use App\Services\AttendanceService;
use App\Services\RecapService;
use App\Services\Sikep\RoleResolver;
use App\Services\TargetResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DashboardController extends Controller
{
    /** Jumlah bulan yang ditampilkan pada grafik tren. */
    private const BULAN_TREN = 6;

    /** Tugas diajukan lebih lama dari ini (hari) dianggap menumpuk. */
    private const HARI_MENUMPUK = 3;

    public function index(Request $request, RoleResolver $roleResolver): Response
    {
        $user = $request->user();
        $roles = $roleResolver->resolve($user->sikep_user_id);

        $now = Period::today();
        $tahun = (int) $now->year;
        $bulan = (int) $now->month;

        $data = [
            'roles' => $roles,
            'periode' => Period::info($tahun, $bulan),
            'periodeLalu' => $this->periodeLaluBelumRealisasi($user, $now),
        ];

        if ($roles['is_katimker']) {
            $data['katimker'] = $this->dataKatimker($user, $tahun, $bulan);
        }

        if ($roles['is_kabid']) {
            $data['kabid'] = $this->dataKabid($user->sikep_user_id, $tahun, $bulan);
        }

        if ($roles['is_admin']) {
            $data['admin'] = $this->dataAdmin($tahun, $bulan);
        }

        return Inertia::render('Dashboard', $data);
    }

    // ─── Periode ─────────────────────────────────────────────────────────

    /**
     * Info periode bulan lalu selama insentifnya belum direalisasi (belum ada hasil insentif
     * final); null bila sudah direalisasi. Katimker dicek atas hasil miliknya, peran lain
     * atas ada/tidaknya hasil final pada periode itu.
     *
     * @return array<string,mixed>|null
     */
    private function periodeLaluBelumRealisasi(User $user, CarbonInterface $hariIni): ?array
    {
        $lalu = $hariIni->copy()->startOfMonth()->subMonthNoOverflow();
        $tahun = (int) $lalu->year;
        $bulan = (int) $lalu->month;

        $hasil = IncentiveResult::query()->where('tahun', $tahun)->where('bulan', $bulan);
        if ($user->sikep_user_id && app(RoleResolver::class)->resolve($user->sikep_user_id)['is_katimker']) {
            $hasil->where('katimker_sikep_user_id', $user->sikep_user_id);
        }

        return $hasil->exists() ? null : Period::info($tahun, $bulan);
    }

    // ─── Katimker ────────────────────────────────────────────────────────

    private function dataKatimker(User $user, int $tahun, int $bulan): array
    {
        $id = $user->sikep_user_id;
        $milik = fn () => TaskReport::query()->milik($id);

        $rekap = app(RecapService::class)->forPeriod($id, $tahun, $bulan);
        $target = app(TargetResolver::class)->efektif($id, $tahun, $bulan);

        $kehadiran = null;
        if ($user->no_absen) {
            try {
                $kehadiran = app(AttendanceService::class)->nilai($id, (int) $user->no_absen, $tahun, $bulan);
            } catch (Throwable) {
                $kehadiran = null; // SIKEP tidak terjangkau: dashboard tetap tampil.
            }
        }

        $insentif = IncentiveResult::query()
            ->where('katimker_sikep_user_id', $id)
            ->orderByDesc('tahun')->orderByDesc('bulan')
            ->first();

        $perluTindakan = $milik()
            ->whereIn('status', [
                TaskReport::STATUS_REVISI,
                TaskReport::STATUS_MENUNGGU_BUKTI,
            ])
            ->orderByRaw("CASE status WHEN 'revisi' THEN 0 ELSE 1 END")
            ->orderByDesc('tanggal')
            ->limit(5)
            ->get(['id', 'judul', 'tanggal', 'status', 'jenis', 'catatan_penilai'])
            ->map(fn (TaskReport $t) => $this->ringkasTugas($t, true))
            ->all();

        $terbaru = $milik()
            ->orderByDesc('tanggal')->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'judul', 'tanggal', 'status', 'jenis', 'durasi_diajukan_menit', 'durasi_disetujui_menit'])
            ->map(fn (TaskReport $t) => $this->ringkasTugas($t))
            ->all();

        return [
            'status' => $this->statusCount($milik()->periode($tahun, $bulan)),
            'ringkas' => $this->statusRingkas($milik()->periode($tahun, $bulan)),
            'jam' => [
                'utama_menit' => $rekap['utama_menit'],
                'tambahan_menit' => $rekap['tambahan_menit'],
                'total_menit' => $rekap['total_menit'],
                'target_utama_menit' => $target['utama_menit'],
                'target_tambahan_menit' => $target['tambahan_menit'],
                'sumber_target' => $target['sumber'],
            ],
            'kehadiran' => $kehadiran,
            'insentif_terakhir' => $insentif ? [
                'tahun' => $insentif->tahun,
                'bulan' => $insentif->bulan,
                'persen_total' => (float) $insentif->persen_total,
                'nominal_insentif' => $insentif->nominal_insentif,
                'nominal_plafon' => $insentif->nominal_plafon,
            ] : null,
            'perlu_tindakan' => $perluTindakan,
            'terbaru' => $terbaru,
            'tren' => $this->tren(fn ($q) => $q->milik($id), 'jam'),
        ];
    }

    // ─── Kabid ───────────────────────────────────────────────────────────

    private function dataKabid(int $kabidId, int $tahun, int $bulan): array
    {
        $untukSaya = fn () => TaskReport::query()->untukKabid($kabidId);

        $binaanIds = ApproverMapping::query()
            ->where('kabid_sikep_user_id', $kabidId)
            ->where('aktif', true)
            ->pluck('katimker_sikep_user_id');

        $antrean = $untukSaya()
            ->where('status', TaskReport::STATUS_DIAJUKAN)
            ->orderBy('diajukan_pada')
            ->limit(6)
            ->get(['id', 'judul', 'tanggal', 'jenis', 'durasi_diajukan_menit', 'diajukan_pada', 'katimker_sikep_user_id']);

        $nama = $this->namaPengguna($antrean->pluck('katimker_sikep_user_id'));

        $menunggu = $untukSaya()->where('status', TaskReport::STATUS_DIAJUKAN)->count();
        $menumpuk = $untukSaya()
            ->where('status', TaskReport::STATUS_DIAJUKAN)
            ->where('diajukan_pada', '<', now()->subDays(self::HARI_MENUMPUK))
            ->count();

        $statusBulanIni = $this->statusCount($untukSaya()->periode($tahun, $bulan));

        $rekapBinaan = app(RecapService::class)
            ->forKatimkerList($binaanIds, $tahun, $bulan)
            ->sortByDesc('total_menit')
            ->values();
        $namaBinaan = $this->namaPengguna($rekapBinaan->pluck('katimker_sikep_user_id'));

        return [
            'menunggu' => $menunggu,
            'menumpuk' => $menumpuk,
            'hari_menumpuk' => self::HARI_MENUMPUK,
            'jumlah_binaan' => $binaanIds->count(),
            'binaan_aktif_bulan_ini' => $rekapBinaan->where('total_menit', '>', 0)->count(),
            'status_bulan_ini' => $statusBulanIni,
            'ringkas_bulan_ini' => $this->statusRingkas($untukSaya()->periode($tahun, $bulan)),
            'antrean' => $antrean->map(fn (TaskReport $t) => [
                'id' => $t->id,
                'judul' => $t->judul,
                'jenis' => $t->jenis,
                'tanggal' => $t->tanggal?->toDateString(),
                'diajukan_pada' => $t->diajukan_pada?->toIso8601String(),
                'durasi_diajukan_menit' => $t->durasi_diajukan_menit,
                'katimker_nama' => $nama[$t->katimker_sikep_user_id] ?? ('#'.$t->katimker_sikep_user_id),
            ])->all(),
            'rekap_binaan' => $rekapBinaan->take(6)->map(fn ($r) => [
                'nama' => $namaBinaan[$r['katimker_sikep_user_id']] ?? ('#'.$r['katimker_sikep_user_id']),
                'utama_menit' => $r['utama_menit'],
                'tambahan_menit' => $r['tambahan_menit'],
                'total_menit' => $r['total_menit'],
            ])->all(),
            'tren' => $this->tren(fn ($q) => $q->untukKabid($kabidId), 'status'),
        ];
    }

    // ─── Admin ───────────────────────────────────────────────────────────

    private function dataAdmin(int $tahun, int $bulan): array
    {
        $mapping = app(ApproverMappingService::class);
        $katimker = $mapping->listKatimker();

        $diajukan = fn () => TaskReport::query()->where('status', TaskReport::STATUS_DIAJUKAN);

        $pengguna = User::query()
            ->where('aktif', true)
            ->selectRaw('role, COUNT(*) as c')
            ->groupBy('role')
            ->pluck('c', 'role');

        $audit = AuditLog::query()->orderByDesc('id')->limit(6)->get();
        $namaAktor = $this->namaPengguna($audit->pluck('aktor_sikep_user_id'));

        return [
            'status_periode' => $this->statusCount(TaskReport::query()->periode($tahun, $bulan)),
            'total_diajukan' => $diajukan()->count(),
            'menumpuk' => $diajukan()->where('diajukan_pada', '<', now()->subDays(self::HARI_MENUMPUK))->count(),
            'hari_menumpuk' => self::HARI_MENUMPUK,
            'katimker_belum_dipetakan' => $mapping->unassigned()->count(),
            'jumlah_katimker' => $katimker->count(),
            'pengguna_aktif' => [
                'admin' => (int) ($pengguna[User::ROLE_ADMIN] ?? 0),
                'kabid' => (int) ($pengguna[User::ROLE_KABID] ?? 0),
                'katimker' => (int) ($pengguna[User::ROLE_KATIMKER] ?? 0),
            ],
            'insentif_final' => IncentiveResult::query()->where('tahun', $tahun)->where('bulan', $bulan)->count(),
            'aktivitas' => $audit->map(fn (AuditLog $a) => [
                'id' => $a->id,
                'aksi' => $a->aksi,
                'aktor' => $a->aktor_sikep_user_id ? ($namaAktor[$a->aktor_sikep_user_id] ?? 'Pengguna #'.$a->aktor_sikep_user_id) : 'Sistem',
                'waktu' => $a->created_at?->toIso8601String(),
            ])->all(),
            'tren' => $this->tren(fn ($q) => $q, 'status'),
        ];
    }

    // ─── Util ────────────────────────────────────────────────────────────

    /**
     * Hitung jumlah tugas per status untuk sebuah query.
     *
     * @return array{menunggu_bukti:int,diajukan:int,revisi:int,disetujui:int,ditolak:int}
     */
    private function statusCount($query): array
    {
        $rows = (clone $query)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'menunggu_bukti' => (int) ($rows[TaskReport::STATUS_MENUNGGU_BUKTI] ?? 0),
            'diajukan' => (int) ($rows[TaskReport::STATUS_DIAJUKAN] ?? 0),
            'revisi' => (int) ($rows[TaskReport::STATUS_REVISI] ?? 0),
            'disetujui' => (int) ($rows[TaskReport::STATUS_DISETUJUI] ?? 0),
            'ditolak' => (int) ($rows[TaskReport::STATUS_DITOLAK] ?? 0),
        ];
    }

    /**
     * Jumlah tugas dan total menit per status (menit disetujui untuk status disetujui,
     * menit diajukan untuk status lain).
     *
     * @return array<string,array{jumlah:int,menit:int}>
     */
    private function statusRingkas($query): array
    {
        $hasil = [];
        foreach ([
            TaskReport::STATUS_MENUNGGU_BUKTI, TaskReport::STATUS_DIAJUKAN, TaskReport::STATUS_REVISI,
            TaskReport::STATUS_DISETUJUI, TaskReport::STATUS_DITOLAK,
        ] as $st) {
            $hasil[$st] = ['jumlah' => 0, 'menit' => 0];
        }

        (clone $query)
            ->selectRaw('status, COUNT(*) as c, COALESCE(SUM(durasi_diajukan_menit),0) as diajukan, COALESCE(SUM(durasi_disetujui_menit),0) as disetujui')
            ->groupBy('status')
            ->get()
            ->each(function ($r) use (&$hasil) {
                $hasil[$r->status] = [
                    'jumlah' => (int) $r->c,
                    'menit' => (int) ($r->status === TaskReport::STATUS_DISETUJUI ? $r->disetujui : $r->diajukan),
                ];
            });

        return $hasil;
    }

    /**
     * Tren N bulan terakhir (termasuk bulan berjalan). Diagregasi di PHP agar
     * tidak bergantung pada fungsi tanggal khusus-DBMS.
     *
     * Mode "jam"   : menit disetujui per jenis (utama/tambahan) → dalam jam.
     * Mode "status": jumlah tugas disetujui / ditolak / diajukan.
     *
     * @param  callable(Builder):Builder  $scope
     * @return array{labels:array<int,string>,series:array<string,array<int,int|float>>}
     */
    private function tren(callable $scope, string $mode): array
    {
        $awal = now()->startOfMonth()->subMonths(self::BULAN_TREN - 1);

        $bulanKey = [];
        $labels = [];
        for ($i = 0; $i < self::BULAN_TREN; $i++) {
            $m = $awal->copy()->addMonths($i);
            $bulanKey[$m->format('Y-m')] = $i;
            $labels[] = $m->format('Y-m');
        }

        $rows = $scope(TaskReport::query())
            ->where('tanggal', '>=', $awal->toDateString())
            ->get(['tanggal', 'status', 'jenis', 'durasi_disetujui_menit']);

        $kosong = array_fill(0, self::BULAN_TREN, 0);
        $series = $mode === 'jam'
            ? ['utama' => $kosong, 'tambahan' => $kosong]
            : ['disetujui' => $kosong, 'revisi' => $kosong, 'ditolak' => $kosong, 'diajukan' => $kosong];

        foreach ($rows as $r) {
            $idx = $bulanKey[Carbon::parse($r->tanggal)->format('Y-m')] ?? null;
            if ($idx === null) {
                continue;
            }

            if ($mode === 'jam') {
                if ($r->status === TaskReport::STATUS_DISETUJUI && isset($series[$r->jenis])) {
                    $series[$r->jenis][$idx] += (int) $r->durasi_disetujui_menit;
                }
            } elseif (isset($series[$r->status])) {
                $series[$r->status][$idx]++;
            }
        }

        if ($mode === 'jam') {
            foreach ($series as $k => $vals) {
                $series[$k] = array_map(fn ($m) => round($m / 60, 1), $vals);
            }
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /** @return array<string,mixed> */
    private function ringkasTugas(TaskReport $t, bool $denganCatatan = false): array
    {
        $row = [
            'id' => $t->id,
            'judul' => $t->judul,
            'jenis' => $t->jenis,
            'status' => $t->status,
            'tanggal' => $t->tanggal?->toDateString(),
            'durasi_diajukan_menit' => $t->durasi_diajukan_menit,
            'durasi_disetujui_menit' => $t->durasi_disetujui_menit,
        ];

        if ($denganCatatan) {
            $row['catatan_penilai'] = $t->catatan_penilai;
        }

        return $row;
    }

    /**
     * Peta sikep_user_id → nama dari tabel user lokal.
     *
     * @param  Collection<int,int|null>  $ids
     * @return Collection<int,string>
     */
    private function namaPengguna(Collection $ids): Collection
    {
        $ids = $ids->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('sikep_user_id', $ids)->pluck('nama', 'sikep_user_id');
    }
}
