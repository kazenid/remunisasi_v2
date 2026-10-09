<?php

namespace App\Services;

use App\Models\Sikep\SikepPenguranganInsentif;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penilaian kehadiran pegawai dari SIKEP (read-only), mereplikasi logika
 * SIKEP/lihat/setting_absensi_rekap.php. Menghasilkan persentase pengurangan
 * insentif akibat absensi, lalu persen_kehadiran = max(0, 100 - total).
 *
 * CATATAN: ini komponen kehadiran untuk PERSIAPAN simulasi insentif;
 * perhitungan/pembayaran insentif final belum aktif.
 */
class AttendanceService
{
    private function conn()
    {
        return DB::connection('sikep');
    }

    /**
     * Nilai kehadiran satu pegawai pada satu periode.
     *
     * @return array{
     *   tersedia:bool, persen_kehadiran:float, total_pengurangan:float,
     *   hari_kerja:int, hadir:int, terlambat:int, pulang_awal:int,
     *   tidak_scan:int, tanpa_keterangan:int, libur:int, cuti:int
     * }
     */
    public function nilai(int $sikepUserId, int $noAbsen, int $tahun, int $bulan): array
    {
        $kosong = [
            'tersedia' => false, 'persen_kehadiran' => 0.0, 'total_pengurangan' => 0.0,
            'hari_kerja' => 0, 'hadir' => 0, 'terlambat' => 0, 'pulang_awal' => 0,
            'tidak_scan' => 0, 'tanpa_keterangan' => 0, 'libur' => 0, 'cuti' => 0,
        ];

        try {
            // Header absensi wajib ada; bila belum, periode dianggap belum tersedia.
            $header = $this->conn()->table('absensi')
                ->where('id_user', $sikepUserId)->where('bulan', $bulan)->where('tahun', $tahun)
                ->first();
            if ($header === null) {
                return $kosong;
            }

            $awal = Carbon::create($tahun, $bulan, 1);
            $jmlHari = (int) $awal->daysInMonth;

            $acc = $kosong;
            $acc['tersedia'] = true;
            $totalPengurangan = 0.0;

            for ($i = 1; $i <= $jmlHari; $i++) {
                $tgl = sprintf('%04d-%02d-%02d', $tahun, $bulan, $i);

                // 1) Cuti approved?
                $cuti = $this->cutiPadaTanggal($sikepUserId, $tgl);
                if ($cuti !== null) {
                    $kat = $this->kategoriCuti((int) $cuti->id_ms_cuti);
                    $totalPengurangan += $this->prosentaseKategori($kat, $tgl);
                    $acc['cuti']++;

                    continue;
                }

                // 2) Jadwal hari itu (dari absensi_detail → ms_jam_kerja).
                $jadwal = $this->jadwal($sikepUserId, $tgl, $bulan, $tahun);
                if ($jadwal === null) {
                    continue; // tidak ada jadwal → lewati
                }
                if ((int) $jadwal->libur === 1) {
                    $acc['libur']++;

                    continue; // hari libur mengikuti SIKEP
                }

                $acc['hari_kerja']++;

                // 3) Scan masuk/keluar.
                [$scanMasuk, $scanKeluar] = $this->scan($noAbsen, $tgl, $jadwal);
                $adaMasuk = $scanMasuk !== null;
                $adaKeluar = $scanKeluar !== null;

                $jadwalMasuk = $tgl.' '.$jadwal->jam_masuk;
                $jadwalPulang = $this->jadwalPulangLengkap($tgl, $jadwal);

                if ($adaMasuk && $scanMasuk > $jadwalMasuk) {
                    $menit = $this->selisihMenit($jadwalMasuk, $scanMasuk);
                    $totalPengurangan += $this->prosentaseReguler($menit, $tgl);
                    $acc['terlambat']++;
                }
                if ($adaKeluar && $scanKeluar < $jadwalPulang) {
                    $menit = $this->selisihMenit($scanKeluar, $jadwalPulang);
                    $totalPengurangan += $this->prosentaseReguler($menit, $tgl);
                    $acc['pulang_awal']++;
                }

                if (! $adaMasuk && ! $adaKeluar) {
                    // Tanpa keterangan (keduanya tidak scan): kategori TK (menimpa, bukan menambah).
                    $totalPengurangan += $this->prosentaseKategori(SikepPenguranganInsentif::KAT_TK, $tgl);
                    $acc['tanpa_keterangan']++;
                } elseif (! $adaMasuk || ! $adaKeluar) {
                    // Salah satu tidak scan.
                    $totalPengurangan += $this->prosentaseKategori(SikepPenguranganInsentif::KAT_TIDAK_SCAN, $tgl);
                    $acc['tidak_scan']++;
                } else {
                    $acc['hadir']++;
                }
            }

            $acc['total_pengurangan'] = round($totalPengurangan, 2);
            $acc['persen_kehadiran'] = round(max(0.0, 100.0 - $totalPengurangan), 2);

            return $acc;
        } catch (QueryException $e) {
            report($e);

            return $kosong;
        }
    }

    private function cutiPadaTanggal(int $sikepUserId, string $tgl): ?object
    {
        return $this->conn()->table('cuti as a')
            ->join('cuti_detail as b', 'b.id_cuti', '=', 'a.id')
            ->where('b.tgl_cuti', $tgl)
            ->where('a.id_user', $sikepUserId)
            ->where('a.approved', 1)
            ->select('a.id_ms_cuti')
            ->first();
    }

    /** Pemetaan jenis cuti SIKEP → kategori pengurangan. */
    private function kategoriCuti(int $idMsCuti): int
    {
        return match ($idMsCuti) {
            3, 8 => SikepPenguranganInsentif::KAT_SAKIT, // Cuti Sakit / Ijin Sakit
            9 => SikepPenguranganInsentif::KAT_DL,        // Dinas Luar
            default => SikepPenguranganInsentif::KAT_CUTI,
        };
    }

    private function jadwal(int $sikepUserId, string $tgl, int $bulan, int $tahun): ?object
    {
        return $this->conn()->table('absensi as a')
            ->join('absensi_detail as b', 'b.id_absensi', '=', 'a.id')
            ->join('ms_jam_kerja as c', 'c.id', '=', 'b.id_ms_jam_kerja')
            ->where('a.id_user', $sikepUserId)
            ->where('b.tgl', $tgl)
            ->select('c.jam_masuk', 'c.jam_pulang', 'c.jam_tengah', 'c.libur', 'c.selisih_waktu')
            ->first();
    }

    private function jadwalPulangLengkap(string $tgl, object $jadwal): string
    {
        // Shift lintas hari: jam pulang < jam masuk → pulang di hari berikutnya.
        if ($jadwal->jam_pulang < $jadwal->jam_masuk) {
            return Carbon::parse($tgl)->addDay()->toDateString().' '.$jadwal->jam_pulang;
        }

        return $tgl.' '.$jadwal->jam_pulang;
    }

    /**
     * @return array{0:?string,1:?string} [scan_masuk, scan_keluar] format 'Y-m-d H:i:00' atau null
     */
    private function scan(int $noAbsen, string $tgl, object $jadwal): array
    {
        $wMulai = (int) config('sikep.absen_window_mulai_jam', 3);
        $wAkhir = (int) config('sikep.absen_window_akhir_jam', 6);

        $filterMulai = $tgl.' '.$jadwal->jam_masuk;

        // jam_tengah lintas hari
        $filterMid = ($jadwal->jam_tengah < $jadwal->jam_masuk)
            ? Carbon::parse($tgl)->addDay()->toDateString().' '.$jadwal->jam_tengah
            : $tgl.' '.$jadwal->jam_tengah;

        $filterAkhir = $this->jadwalPulangLengkap($tgl, $jadwal);

        $masuk = $this->conn()->table('absensi_excel_detail')
            ->selectRaw("DATE_FORMAT(tanggal_scan,'%Y-%m-%d %H:%i:00') as s")
            ->where('no_absen', $noAbsen)
            ->whereRaw('tanggal_scan BETWEEN DATE_SUB(?, INTERVAL ? HOUR) AND ?', [$filterMulai, $wMulai, $filterMid])
            ->orderBy('tanggal_scan', 'asc')
            ->value('s');

        $keluar = $this->conn()->table('absensi_excel_detail')
            ->selectRaw("DATE_FORMAT(tanggal_scan,'%Y-%m-%d %H:%i:00') as s")
            ->where('no_absen', $noAbsen)
            ->whereRaw('tanggal_scan BETWEEN ? AND DATE_ADD(?, INTERVAL ? HOUR)', [$filterMid, $filterAkhir, $wAkhir])
            ->orderBy('tanggal_scan', 'desc')
            ->value('s');

        return [$masuk ?: null, $keluar ?: null];
    }

    private function selisihMenit(string $awal, string $akhir): int
    {
        return (int) floor(Carbon::parse($akhir)->diffInMinutes(Carbon::parse($awal), true));
    }

    /** Persentase pengurangan kategori Reguler (terlambat/pulang awal) by menit. */
    private function prosentaseReguler(int $menit, string $tgl): float
    {
        $row = SikepPenguranganInsentif::query()
            ->where('id_ms_pengurangan_insentif_kategori', SikepPenguranganInsentif::KAT_REGULER)
            ->where('menit_mulai', '<=', $menit)
            ->where('menit_akhir', '>=', $menit)
            ->where('berlaku_mulai', '<=', $tgl)
            ->where('berlaku_akhir', '>=', $tgl)
            ->first();

        return (float) ($row->prosentase ?? 0);
    }

    /** Persentase pengurangan kategori non-reguler (cuti/tidak scan/TK/DL). */
    private function prosentaseKategori(int $kategori, string $tgl): float
    {
        $row = SikepPenguranganInsentif::query()
            ->where('id_ms_pengurangan_insentif_kategori', $kategori)
            ->where('berlaku_mulai', '<=', $tgl)
            ->where('berlaku_akhir', '>=', $tgl)
            ->first();

        return (float) ($row->prosentase ?? 0);
    }
}
