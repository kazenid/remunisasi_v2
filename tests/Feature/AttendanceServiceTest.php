<?php

namespace Tests\Feature;

use App\Services\AttendanceService;
use Tests\TestCase;

/**
 * Catatan: AttendanceService membaca SIKEP (koneksi 'sikep'). Test ini
 * memverifikasi bentuk hasil & penanganan "tidak tersedia" tanpa bergantung
 * pada data spesifik. Ketepatan angka diverifikasi via sanity-check data nyata
 * terhadap setting_absensi_rekap.php (lihat spec 1.3).
 */
class AttendanceServiceTest extends TestCase
{
    public function test_periode_tanpa_data_mengembalikan_tidak_tersedia(): void
    {
        $svc = app(AttendanceService::class);

        // sikep_user_id & no_absen yang pasti tak punya header absensi di periode ini.
        $hasil = $svc->nilai(2000000, 9999999, 1999, 1);

        $this->assertFalse($hasil['tersedia']);
        $this->assertSame(0.0, $hasil['persen_kehadiran']);
        $this->assertArrayHasKey('total_pengurangan', $hasil);
        $this->assertArrayHasKey('hari_kerja', $hasil);
    }
}
