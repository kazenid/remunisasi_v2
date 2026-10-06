<?php

namespace Tests\Feature;

use App\Models\PeriodeKinerja;
use App\Models\TugasHarian;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\InsentifCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KatimkerApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected PeriodeKinerja $periode;
    protected User $admin;
    protected User $kabidAtasan;
    protected User $katimker;
    protected User $staffBiasa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periode = PeriodeKinerja::create([
            'tahun' => 2026,
            'bulan' => 10,
            'nama' => 'Oktober 2026',
            'tgl_cutoff' => 10,
            'tgl_cair' => 15,
            'target_jam_utama' => 150.00,
            'target_jam_tambahan' => 15.00,
            'bobot_kehadiran' => 40.00,
            'bobot_tugas_utama' => 50.00,
            'bobot_tugas_tambahan' => 10.00,
            'nominal_dasar' => 3000000.00,
            'status' => 'aktif',
        ]);

        $this->admin = User::create([
            'name' => 'Admin Sistem',
            'email' => 'admin@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'admin',
            'nip' => '198001012005011001',
            'is_active' => true,
        ]);

        $this->kabidAtasan = User::create([
            'name' => 'Kabid Penunjang (Atasan)',
            'email' => 'kabid.penunjang@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'kabid',
            'nip' => '197501012000011001',
            'is_active' => true,
        ]);

        $this->katimker = User::create([
            'name' => 'dr. Budi (Katimker CSSD)',
            'email' => 'katimker.budi@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'staff',
            'nip' => '198801012015011001',
            'nama_unit' => 'Instalasi CSSD',
            'id_unit' => 10,
            'is_katimker' => true,
            'atasan_langsung_id' => $this->kabidAtasan->id,
            'is_active' => true,
        ]);

        $this->staffBiasa = User::create([
            'name' => 'Siti (Staf)',
            'email' => 'siti@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'staff',
            'nip' => '199501012020012001',
            'nama_unit' => 'Instalasi CSSD',
            'id_unit' => 10,
            'is_katimker' => false,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_assign_and_update_katimker(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.katimker.update', $this->staffBiasa), [
            'is_katimker' => true,
            'atasan_langsung_id' => $this->kabidAtasan->id,
        ]);

        $response->assertRedirect();
        $this->staffBiasa->refresh();
        $this->assertTrue($this->staffBiasa->is_katimker);
        $this->assertEquals($this->kabidAtasan->id, $this->staffBiasa->atasan_langsung_id);
    }

    public function test_non_admin_cannot_manage_katimker(): void
    {
        $response = $this->actingAs($this->staffBiasa)->put(route('admin.katimker.update', $this->katimker), [
            'is_katimker' => false,
            'atasan_langsung_id' => null,
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_bulk_assign_katimkers(): void
    {
        $otherStaff = User::create([
            'name' => 'Joko (Staf)',
            'email' => 'joko@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'staff',
            'nip' => '199601012021011001',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.katimker.bulk-assign'), [
            'user_ids' => [$this->staffBiasa->id, $otherStaff->id],
            'atasan_langsung_id' => $this->kabidAtasan->id,
        ]);

        $response->assertRedirect();
        $this->assertTrue($this->staffBiasa->fresh()->is_katimker);
        $this->assertTrue($otherStaff->fresh()->is_katimker);
        $this->assertEquals($this->kabidAtasan->id, $this->staffBiasa->fresh()->atasan_langsung_id);
        $this->assertEquals($this->kabidAtasan->id, $otherStaff->fresh()->atasan_langsung_id);
    }

    public function test_katimker_submits_task_sets_level_to_atasan_katimker(): void
    {
        $response = $this->actingAs($this->katimker)->post(route('tugas.store'), [
            'tanggal' => '2026-10-06',
            'kategori' => 'utama',
            'judul' => 'Koordinasi Penataan Sterilisasi Alat Medis',
            'jam_mulai' => '08:00',
            'jam_selesai' => '14:00',
            'durasi_jam' => 6.0,
            'deskripsi' => 'Pengawasan alur CSSD',
        ]);

        $response->assertRedirect();

        $tugas = TugasHarian::where('user_id', $this->katimker->id)->first();
        $this->assertNotNull($tugas);
        $this->assertEquals('diajukan', $tugas->status);
        $this->assertEquals('atasan_katimker', $tugas->level_saat_ini);
    }

    public function test_atasan_langsung_can_approve_katimker_task(): void
    {
        $tugas = TugasHarian::create([
            'user_id' => $this->katimker->id,
            'periode_id' => $this->periode->id,
            'tanggal' => '2026-10-06',
            'kategori' => 'utama',
            'judul' => 'Pengawasan Rutin CSSD',
            'jam_mulai' => '08:00',
            'jam_selesai' => '14:00',
            'durasi_jam' => 6.00,
            'status' => 'diajukan',
            'level_saat_ini' => 'atasan_katimker',
            'tgl_pengajuan' => now(),
        ]);

        $response = $this->actingAs($this->kabidAtasan)->post(route('approval.approve', $tugas), [
            'jam_disetujui' => 6.0,
            'catatan' => 'Sesuai tupoksi Katimker',
        ]);

        $response->assertRedirect();
        $tugas->refresh();

        $this->assertEquals('disetujui_atasan_katimker', $tugas->status);
        $this->assertEquals('selesai', $tugas->level_saat_ini);
        $this->assertEquals(6.0, (float) $tugas->jam_disetujui);
    }

    public function test_unauthorized_user_cannot_approve_katimker_task(): void
    {
        $tugas = TugasHarian::create([
            'user_id' => $this->katimker->id,
            'periode_id' => $this->periode->id,
            'tanggal' => '2026-10-06',
            'kategori' => 'utama',
            'judul' => 'Pengawasan Rutin CSSD',
            'jam_mulai' => '08:00',
            'jam_selesai' => '14:00',
            'durasi_jam' => 6.00,
            'status' => 'diajukan',
            'level_saat_ini' => 'atasan_katimker',
            'tgl_pengajuan' => now(),
        ]);

        $response = $this->actingAs($this->staffBiasa)->post(route('approval.approve', $tugas), [
            'jam_disetujui' => 6.0,
            'catatan' => 'Coba verifikasi',
        ]);

        $response->assertForbidden();
    }

    public function test_insentif_calculator_counts_katimker_approved_tasks(): void
    {
        TugasHarian::create([
            'user_id' => $this->katimker->id,
            'periode_id' => $this->periode->id,
            'tanggal' => '2026-10-06',
            'kategori' => 'utama',
            'judul' => 'Pengawasan Rutin CSSD',
            'jam_mulai' => '08:00',
            'jam_selesai' => '16:00',
            'durasi_jam' => 8.00,
            'jam_disetujui' => 8.00,
            'status' => 'disetujui_atasan_katimker',
            'level_saat_ini' => 'selesai',
            'tgl_pengajuan' => now(),
        ]);

        $calculator = app(InsentifCalculatorService::class);
        $rekap = $calculator->calculateForUser($this->katimker, $this->periode);

        $this->assertEquals(8.00, (float) $rekap->jam_utama_disetujui);
        $this->assertGreaterThan(0, (float) $rekap->capaian_jam_utama);
    }
}