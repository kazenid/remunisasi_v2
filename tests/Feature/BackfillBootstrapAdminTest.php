<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\SetsUpSikepConnection;
use Tests\TestCase;

/**
 * Backfill Opsi A + bootstrap admin via app:set-admin.
 * Opsi A: non-admin lama → role=null, aktif TETAP true (default). Admin lama →
 * role=admin, aktif=true.
 */
class BackfillBootstrapAdminTest extends TestCase
{
    use RefreshDatabase, SetsUpSikepConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSikepSqlite();
    }

    /**
     * Simulasikan logika backfill migrasi pada data yang sudah ada (skema sudah
     * termigrasi oleh RefreshDatabase).
     */
    private function jalankanBackfill(): void
    {
        DB::table('users')->where('is_admin', true)->update(['role' => 'admin', 'aktif' => true]);
        DB::table('users')->where('is_admin', false)->update(['role' => null]);
    }

    public function test_backfill_opsi_a_non_admin_role_null_tetap_aktif(): void
    {
        // Buat user non-admin "lama" tanpa role (seperti sebelum migrasi).
        DB::table('users')->insert([
            'sikep_user_id' => 910,
            'username' => 'lama910',
            'nama' => 'NON ADMIN LAMA',
            'no_absen' => 910,
            'is_admin' => false,
            'role' => 'katimker', // nilai pra-backfill; backfill akan menormalkan ke null
            'aktif' => true,
        ]);

        $this->jalankanBackfill();

        $user = User::where('sikep_user_id', 910)->first();
        $this->assertNull($user->role);
        $this->assertTrue($user->aktif, 'Opsi A: non-admin lama tetap aktif.');
    }

    public function test_backfill_admin_lama_role_admin_aktif(): void
    {
        DB::table('users')->insert([
            'sikep_user_id' => 911,
            'username' => 'admin911',
            'nama' => 'ADMIN LAMA',
            'no_absen' => 911,
            'is_admin' => true,
            'role' => null,
            'aktif' => true,
        ]);

        $this->jalankanBackfill();

        $user = User::where('sikep_user_id', 911)->first();
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->aktif);
    }

    public function test_bootstrap_admin_via_command_bisa_login(): void
    {
        // Hanya ada non-admin; tidak ada admin sama sekali.
        DB::table('users')->insert([
            'sikep_user_id' => 912,
            'username' => 'lama912',
            'nama' => 'NON ADMIN',
            'no_absen' => 912,
            'is_admin' => false,
            'role' => null,
            'aktif' => true,
        ]);
        $this->jalankanBackfill();

        // Pegawai SIKEP untuk dijadikan admin bootstrap.
        $this->buatPegawaiSikep(913, 'BOOTSTRAP ADMIN', 'uji913');

        $exit = Artisan::call('app:set-admin', ['login' => 'uji913']);
        $this->assertSame(0, $exit);

        $admin = User::where('sikep_user_id', 913)->first();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_admin);
        $this->assertTrue($admin->aktif);
    }

    public function test_set_admin_off_mencabut_role_tanpa_mengubah_aktif(): void
    {
        $this->buatPegawaiSikep(914, 'MANTAN ADMIN', 'uji914');
        User::factory()->admin()->create(['sikep_user_id' => 914, 'username' => 'uji914']);

        $exit = Artisan::call('app:set-admin', ['login' => 'uji914', '--off' => true]);
        $this->assertSame(0, $exit);

        $user = User::where('sikep_user_id', 914)->first();
        $this->assertNull($user->role);
        $this->assertFalse($user->is_admin);
        $this->assertTrue($user->aktif, '--off tidak mengubah aktif.');
    }
}
