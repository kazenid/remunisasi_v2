<?php

namespace Tests\Feature;

use App\Models\PeriodeKinerja;
use App\Models\TugasHarian;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\InsentifCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemunisasiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_remunisasi_workflow(): void
    {
        $periode = PeriodeKinerja::create([
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

        $staf = User::create([
            'name' => 'Staf Pelaksana Test',
            'email' => 'staf@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'staf',
            'nip' => '199001012020011001',
            'nama_unit' => 'Ruang Teratai',
            'id_unit' => 1,
            'is_active' => true,
        ]);

        $katker = User::create([
            'name' => 'Kepala Ruang Teratai',
            'email' => 'katker@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'katker',
            'id_unit' => 1,
            'is_active' => true,
        ]);

        $kabid = User::create([
            'name' => 'Kabid Pelayanan Medik',
            'email' => 'kabid@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'kabid',
            'is_active' => true,
        ]);

        $wadir = User::create([
            'name' => 'Wadir Pelayanan',
            'email' => 'wadir@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'wadir',
            'is_active' => true,
        ]);

        $direktur = User::create([
            'name' => 'Direktur RSUD Bangil',
            'email' => 'direktur@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'direktur',
            'is_active' => true,
        ]);

        // 1. Staf submit task harian
        $tugas = TugasHarian::create([
            'user_id' => $staf->id,
            'periode_id' => $periode->id,
            'tanggal' => '2026-10-05',
            'kategori' => 'utama',
            'judul' => 'Pelayanan Rawat Inap Ruang Teratai',
            'jam_mulai' => '07:30',
            'jam_selesai' => '14:30',
            'durasi_jam' => 7.00,
            'status' => 'diajukan',
            'level_saat_ini' => 'katker',
            'tgl_pengajuan' => now(),
        ]);

        $this->assertEquals('diajukan', $tugas->status);
        $this->assertEquals('katker', $tugas->level_saat_ini);

        // 2. Katker approves -> advances to kabid
        $approvalService = app(ApprovalService::class);
        $approvalService->process($tugas, $katker, 'setuju', 7.00, null, 'Disetujui Katker');
        $tugas->refresh();
        $this->assertEquals('disetujui_katker', $tugas->status);
        $this->assertEquals('kabid', $tugas->level_saat_ini);

        // 3. Kabid approves -> advances to wadir
        $approvalService->process($tugas, $kabid, 'setuju', 7.00, null, 'Disetujui Kabid');
        $tugas->refresh();
        $this->assertEquals('disetujui_kabid', $tugas->status);
        $this->assertEquals('wadir', $tugas->level_saat_ini);

        // 4. Wadir approves -> advances to direktur
        $approvalService->process($tugas, $wadir, 'setuju', 7.00, null, 'Disetujui Wadir');
        $tugas->refresh();
        $this->assertEquals('disetujui_wadir', $tugas->status);
        $this->assertEquals('direktur', $tugas->level_saat_ini);

        // 5. Direktur final approval
        $approvalService->process($tugas, $direktur, 'setuju', 7.00, null, 'Disetujui Direktur Final');
        $tugas->refresh();
        $this->assertEquals('disetujui_direktur', $tugas->status);
        $this->assertEquals('selesai', $tugas->level_saat_ini);

        // 6. Test Insentif Calculation
        $calcService = app(InsentifCalculatorService::class);
        $rekap = $calcService->calculateForUser($staf, $periode);

        $this->assertNotNull($rekap);
        $this->assertEquals(7.00, (float) $rekap->jam_utama_disetujui);
        $this->assertGreaterThan(0, (float) $rekap->total_persen_kinerja);
        $this->assertGreaterThan(0, (float) $rekap->nominal_insentif);
    }

    public function test_tugas_controller_store_and_submit(): void
    {
        $periode = PeriodeKinerja::create([
            'tahun' => 2026,
            'bulan' => 10,
            'nama' => 'Oktober 2026',
            'tgl_cutoff' => 10,
            'tgl_cair' => 15,
            'target_jam_utama' => 150.00,
            'nominal_dasar' => 3000000.00,
            'status' => 'aktif',
        ]);

        $staf = User::create([
            'name' => 'Staf Pelaksana',
            'email' => 'staf2@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'staf',
            'nip' => '199001012020011002',
            'is_active' => true,
        ]);

        $response = $this->actingAs($staf)->post(route('tugas.store'), [
            'tanggal' => '2026-10-06',
            'kategori' => 'utama',
            'judul' => 'Visite Pasien Kelas 1',
            'jam_mulai' => '08:00',
            'jam_selesai' => '12:00',
            'durasi_jam' => 4.0,
            'deskripsi' => 'Melakukan pemantauan tanda-tanda vital',
        ]);

        $response->assertRedirect();
        
        $this->assertDatabaseHas('tugas_harian', [
            'user_id' => $staf->id,
            'judul' => 'Visite Pasien Kelas 1',
            'status' => 'diajukan',
            'level_saat_ini' => 'katker',
        ]);
    }

    public function test_approval_controller_approve(): void
    {
        $periode = PeriodeKinerja::create([
            'tahun' => 2026,
            'bulan' => 10,
            'nama' => 'Oktober 2026',
            'tgl_cutoff' => 10,
            'tgl_cair' => 15,
            'target_jam_utama' => 150.00,
            'nominal_dasar' => 3000000.00,
            'status' => 'aktif',
        ]);

        $staf = User::create([
            'name' => 'Staf Pelaksana',
            'email' => 'staf3@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'staf',
            'nip' => '199001012020011003',
            'id_unit' => 5,
            'is_active' => true,
        ]);

        $katker = User::create([
            'name' => 'Kepala Ruang 5',
            'email' => 'katker5@rsudbangil.test',
            'password' => bcrypt('secret'),
            'role' => 'katker',
            'id_unit' => 5,
            'is_active' => true,
        ]);

        $tugas = TugasHarian::create([
            'user_id' => $staf->id,
            'periode_id' => $periode->id,
            'tanggal' => '2026-10-06',
            'kategori' => 'utama',
            'judul' => 'Tugas Menunggu Katker',
            'jam_mulai' => '08:00',
            'jam_selesai' => '12:00',
            'durasi_jam' => 4.00,
            'status' => 'diajukan',
            'level_saat_ini' => 'katker',
            'tgl_pengajuan' => now(),
        ]);

        $response = $this->actingAs($katker)->post(route('approval.approve', $tugas), [
            'jam_disetujui' => 4.0,
            'catatan' => 'OK pekerjaan terlaksana dengan baik',
        ]);

        $response->assertRedirect();
        $tugas->refresh();
        $this->assertEquals('disetujui_katker', $tugas->status);
        $this->assertEquals('kabid', $tugas->level_saat_ini);
    }

}

