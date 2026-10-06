<?php

namespace App\Services;

use App\Models\PeriodeKinerja;
use App\Models\RekapInsentif;
use App\Models\Sikep\Absensi;
use App\Models\TugasHarian;
use App\Models\User;

class InsentifCalculatorService
{
    /**
     * Status tugas yang dianggap sudah tervalidasi penuh dan dihitung ke insentif.
     */
    public const STATUS_DISETUJUI = [
        'disetujui_katker',
        'disetujui_kabid',
        'disetujui_wadir',
        'disetujui_direktur',
        'disetujui_atasan_katimker',
    ];

    /**
     * Calculate and store monthly incentive recap for a specific user and period.
     */
    public function calculateForUser(User $user, PeriodeKinerja $periode): RekapInsentif
    {
        // 1. Calculate Attendance Percentage from SIKEP
        $persenKehadiran = $this->calculateAttendanceRate($user->id, $periode->tahun, $periode->bulan);

        // 2. Sum Approved Hours for Main Tasks (kategori 'utama')
        $jamUtamaDisetujui = TugasHarian::where('user_id', $user->id)
            ->where('periode_id', $periode->id)
            ->where('kategori', 'utama')
            ->whereIn('status', self::STATUS_DISETUJUI)
            ->sum('jam_disetujui');

        // Target jam utama (standar 150 jam)
        $targetJamUtama = (float) $periode->target_jam_utama;
        $capaianUtama = $targetJamUtama > 0
            ? min(100.00, round(($jamUtamaDisetujui / $targetJamUtama) * 100, 2))
            : 0.00;

        // 3. Sum Approved Hours for Additional Tasks (kategori 'tambahan')
        $jamTambahanDisetujui = TugasHarian::where('user_id', $user->id)
            ->where('periode_id', $periode->id)
            ->where('kategori', 'tambahan')
            ->whereIn('status', self::STATUS_DISETUJUI)
            ->sum('jam_disetujui');

        // Target jam tambahan (standar 15 jam)
        $targetJamTambahan = (float) $periode->target_jam_tambahan;
        $capaianTambahan = $targetJamTambahan > 0
            ? min(100.00, round(($jamTambahanDisetujui / $targetJamTambahan) * 100, 2))
            : 0.00;

        // 4. Calculate Weighted Scores
        $bobotKehadiran = (float) $periode->bobot_kehadiran; // 40%
        $bobotUtama = (float) $periode->bobot_tugas_utama;     // 50%
        $bobotTambahan = (float) $periode->bobot_tugas_tambahan; // 10%

        $skorKehadiran = round(($persenKehadiran * $bobotKehadiran) / 100, 2);
        $skorUtama = round(($capaianUtama * $bobotUtama) / 100, 2);
        $skorTambahan = round(($capaianTambahan * $bobotTambahan) / 100, 2);

        $totalPersenKinerja = min(100.00, round($skorKehadiran + $skorUtama + $skorTambahan, 2));

        // 5. Calculate Monetary Incentive
        $nominalDasar = (float) $periode->nominal_dasar;
        $nominalInsentif = round(($nominalDasar * $totalPersenKinerja) / 100, 2);

        // 6. Upsert into RekapInsentif
        return RekapInsentif::updateOrCreate(
            [
                'user_id' => $user->id,
                'periode_id' => $periode->id,
            ],
            [
                'persentase_kehadiran' => $persenKehadiran,
                'jam_utama_disetujui' => $jamUtamaDisetujui,
                'target_jam_utama' => $targetJamUtama,
                'capaian_jam_utama' => $capaianUtama,
                'jam_tambahan_disetujui' => $jamTambahanDisetujui,
                'target_jam_tambahan' => $targetJamTambahan,
                'capaian_jam_tambahan' => $capaianTambahan,
                'skor_kehadiran' => $skorKehadiran,
                'skor_utama' => $skorUtama,
                'skor_tambahan' => $skorTambahan,
                'total_persen_kinerja' => $totalPersenKinerja,
                'nominal_dasar' => $nominalDasar,
                'nominal_insentif' => $nominalInsentif,
            ]
        );
    }

    /**
     * Calculate attendance rate from SIKEP absensi records. Defaults to 100.00% if no deductions.
     */
    public function calculateAttendanceRate(int $userId, int $tahun, int $bulan): float
    {
        $absensi = Absensi::where('id_user', $userId)
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->first();

        if (!$absensi) {
            return 100.00;
        }

        $details = $absensi->details;
        if ($details->isEmpty()) {
            return 100.00;
        }

        $totalDays = $details->count();
        $attendedDays = $details->filter(function ($d) {
            return !empty($d->jam_masukx) || !empty($d->total_jam_kerja);
        })->count();

        if ($totalDays === 0) {
            return 100.00;
        }

        return min(100.00, round(($attendedDays / $totalDays) * 100, 2));
    }
}
