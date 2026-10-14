<?php

namespace Tests\Feature;

use App\Models\TaskReport;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IncentiveReportAndTaskDetailTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role, int $sikepId = 111): User
    {
        $isAdmin = $role === 'admin';
        $user = User::factory()->create([
            'sikep_user_id' => $sikepId,
            'role' => $role,
            'is_admin' => $isAdmin,
            'aktif' => true,
            'two_factor_confirmed_at' => $isAdmin ? now() : null,
        ]);

        $this->mock(RoleResolver::class, function ($m) use ($role) {
            $m->shouldReceive('resolve')->andReturn([
                'roles' => [$role],
                'is_katimker' => $role === 'katimker',
                'is_kabid' => $role === 'kabid',
                'is_admin' => $isAdmin = $role === 'admin',
            ]);
        });

        $this->actingAs($user);

        return $user;
    }

    public function test_admin_membuka_laporan_insentif_periode(): void
    {
        $this->actingAsRole('admin');

        $this->get(route('admin.insentif.hitung.laporan', ['tahun' => 2026, 'bulan' => 9]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/LaporanInsentif')
                ->where('laporan.label', 'September 2026')
                ->where('laporan.status', 'Simulasi')
                ->where('laporan.target_realisasi', '2026-10-15')
                ->has('laporan.total.nominal_insentif'));
    }

    public function test_admin_mengunduh_excel_valid(): void
    {
        $this->actingAsRole('admin');

        $response = $this->get(route('admin.insentif.hitung.unduh', ['tahun' => 2026, 'bulan' => 9]));

        $response->assertOk();
        $response->assertDownload('laporan-insentif-2026-09.xlsx');
        // Berkas XLSX adalah arsip ZIP: diawali "PK".
        $this->assertSame('PK', substr(file_get_contents($response->baseResponse->getFile()->getPathname()), 0, 2));
        $this->assertDatabaseHas('audit_logs', ['aksi' => 'incentive.export']);
    }

    public function test_non_admin_tidak_dapat_laporan_maupun_unduh(): void
    {
        $this->actingAsRole('katimker');

        $this->get(route('admin.insentif.hitung.laporan'))->assertForbidden();
        $this->get(route('admin.insentif.hitung.unduh'))->assertForbidden();
    }

    public function test_katimker_melihat_detail_tugas_disetujui(): void
    {
        $this->actingAsRole('katimker', 500);
        $t = TaskReport::create([
            'katimker_sikep_user_id' => 500, 'kabid_sikep_user_id' => 700, 'tanggal' => '2026-10-06',
            'jenis' => 'utama', 'judul' => 'Visite', 'durasi_diajukan_menit' => 120, 'durasi_disetujui_menit' => 90,
            'status' => 'disetujui', 'catatan_penilai' => 'Sebagian di luar jam efektif',
        ]);

        $this->get(route('katimker.tugas.show', $t->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Katimker/TaskDetail')
                ->where('tugas.status', 'disetujui')
                ->where('tugas.durasi_disetujui_menit', 90)
                ->where('tugas.dapat_diubah', false));
    }

    public function test_detail_tugas_milik_orang_lain_ditolak(): void
    {
        $this->actingAsRole('katimker', 500);
        $t = TaskReport::create([
            'katimker_sikep_user_id' => 999, 'tanggal' => '2026-10-06', 'jenis' => 'utama',
            'judul' => 'Orang lain', 'durasi_diajukan_menit' => 60, 'status' => 'disetujui',
        ]);

        $this->get(route('katimker.tugas.show', $t->id))->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
