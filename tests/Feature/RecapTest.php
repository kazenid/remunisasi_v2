<?php

namespace Tests\Feature;

use App\Models\ApproverMapping;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\RecapService;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RecapTest extends TestCase
{
    use RefreshDatabase;

    private int $katimkerId = 500;

    private function buatTugas(string $jenis, string $status, int $diajukan, ?int $disetujui, string $tgl = '2026-10-06', ?int $pemilik = null): TaskReport
    {
        return TaskReport::create([
            'katimker_sikep_user_id' => $pemilik ?? $this->katimkerId,
            'tanggal' => $tgl,
            'jenis' => $jenis,
            'judul' => 'T',
            'durasi_diajukan_menit' => $diajukan,
            'durasi_disetujui_menit' => $disetujui,
            'status' => $status,
        ]);
    }

    public function test_hanya_disetujui_dihitung_dan_dipisah_per_jenis(): void
    {
        // Disetujui: utama 120 + 60, tambahan 30. Lainnya diabaikan.
        $this->buatTugas('utama', 'disetujui', 150, 120);
        $this->buatTugas('utama', 'disetujui', 90, 60);
        $this->buatTugas('tambahan', 'disetujui', 45, 30);
        $this->buatTugas('utama', 'diajukan', 100, null);   // belum dinilai
        $this->buatTugas('utama', 'ditolak', 100, null);    // ditolak
        $this->buatTugas('tambahan', 'draf', 100, null);    // draf

        $rekap = app(RecapService::class)->forPeriod($this->katimkerId, 2026, 10);

        $this->assertSame(180, $rekap['utama_menit']);
        $this->assertSame(30, $rekap['tambahan_menit']);
        $this->assertSame(210, $rekap['total_menit']);
    }

    public function test_periode_lain_tidak_ikut(): void
    {
        $this->buatTugas('utama', 'disetujui', 120, 120, '2026-10-06');
        $this->buatTugas('utama', 'disetujui', 120, 120, '2026-09-06'); // bulan lain

        $rekap = app(RecapService::class)->forPeriod($this->katimkerId, 2026, 10);
        $this->assertSame(120, $rekap['utama_menit']);
    }

    public function test_katimker_hanya_melihat_rekap_sendiri(): void
    {
        $this->buatTugas('utama', 'disetujui', 120, 120, '2026-10-06', $this->katimkerId);
        $this->buatTugas('utama', 'disetujui', 999, 999, '2026-10-06', 888); // milik orang lain

        $user = User::factory()->create(['sikep_user_id' => $this->katimkerId]);
        $this->mock(RoleResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
            'roles' => ['katimker'], 'is_katimker' => true, 'is_kabid' => false, 'is_admin' => false,
        ]));
        $this->actingAs($user);

        $this->get(route('katimker.rekap.index', ['tahun' => 2026, 'bulan' => 10]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Rekap/Index')
                ->where('ringkasan.utama_menit', 120));
    }

    public function test_kabid_melihat_rekap_binaan_saja(): void
    {
        $kabidId = 700;
        ApproverMapping::create(['katimker_sikep_user_id' => $this->katimkerId, 'kabid_sikep_user_id' => $kabidId]);
        $this->buatTugas('utama', 'disetujui', 120, 120, '2026-10-06', $this->katimkerId);
        // Katimker lain yang BUKAN binaan kabid ini
        $this->buatTugas('utama', 'disetujui', 300, 300, '2026-10-06', 888);

        $kabid = User::factory()->create(['sikep_user_id' => $kabidId]);
        $this->mock(RoleResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
            'roles' => ['kabid'], 'is_katimker' => false, 'is_kabid' => true, 'is_admin' => false,
        ]));
        $this->actingAs($kabid);

        $this->get(route('kabid.rekap.index', ['tahun' => 2026, 'bulan' => 10]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Rekap/Index')
                ->has('baris', 1) // hanya 1 binaan
                ->where('baris.0.katimker_sikep_user_id', $this->katimkerId));
    }

    public function test_katimker_tak_boleh_akses_rekap_admin(): void
    {
        $user = User::factory()->create(['sikep_user_id' => $this->katimkerId]);
        $this->mock(RoleResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
            'roles' => ['katimker'], 'is_katimker' => true, 'is_kabid' => false, 'is_admin' => false,
        ]));
        $this->actingAs($user);

        $this->get(route('admin.rekap.index'))->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
