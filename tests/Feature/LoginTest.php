<?php

namespace Tests\Feature;

use App\Models\Sikep\SikepUser;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use App\Services\Sikep\SikepAuthService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Feature test LoginController. SikepAuthService di-mock agar tidak bergantung
 * pada koneksi SIKEP hidup. Gerbang record lokal (role/aktif) menentukan pesan
 * penolakan; RoleResolver nyata membaca kolom lokal.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSikepUser(int $id = 100): SikepUser
    {
        $u = new SikepUser;
        $u->id = $id;
        $u->username = 'uji'.$id;
        $u->no_absen = 900000 + $id;
        $u->nama = 'PENGGUNA UJI';
        $u->gelar_depan = 'dr';
        $u->gelar_belakang = null;
        $u->aktif = 1;

        return $u;
    }

    private function mockSikepAttempt(SikepUser $sikepUser): void
    {
        $this->mock(SikepAuthService::class, function ($m) use ($sikepUser) {
            $m->shouldReceive('attempt')->once()->andReturn($sikepUser);
        });
    }

    public function test_login_sukses_dengan_record_lokal_aktif_berperan(): void
    {
        $sikepUser = $this->fakeSikepUser(101);
        User::factory()->katimker()->create(['sikep_user_id' => 101, 'username' => 'uji101']);

        $this->mockSikepAttempt($sikepUser);

        $response = $this->post('/login', ['login' => 'uji101', 'password' => 'rahasia']);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        // Identitas disegarkan dari SIKEP (nama lengkap dengan gelar).
        $this->assertDatabaseHas('users', [
            'sikep_user_id' => 101,
            'nama' => 'dr PENGGUNA UJI',
            'role' => 'katimker',
        ]);
    }

    public function test_login_gagal_memberi_pesan_generik(): void
    {
        $this->mock(SikepAuthService::class, function ($m) {
            $m->shouldReceive('attempt')->once()->andReturnNull();
        });
        $this->mock(RoleResolver::class, function ($m) {
            $m->shouldNotReceive('resolve');
        });

        $response = $this->from('/login')->post('/login', ['login' => 'siapa', 'password' => 'salah']);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['login' => 'Kredensial yang diberikan tidak cocok.']);
        $this->assertGuest();
    }

    public function test_login_tanpa_record_lokal_ditolak(): void
    {
        $sikepUser = $this->fakeSikepUser(102);
        $this->mockSikepAttempt($sikepUser);

        $response = $this->from('/login')->post('/login', ['login' => 'uji102', 'password' => 'rahasia']);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors([
            'login' => 'Akun Anda belum terdaftar/diaktifkan di aplikasi. Hubungi administrator.',
        ]);
        $this->assertGuest();
        // Tidak membuat record otomatis.
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_record_nonaktif_ditolak(): void
    {
        $sikepUser = $this->fakeSikepUser(103);
        User::factory()->katimker()->nonaktif()->create(['sikep_user_id' => 103, 'username' => 'uji103']);
        $this->mockSikepAttempt($sikepUser);

        $response = $this->from('/login')->post('/login', ['login' => 'uji103', 'password' => 'rahasia']);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors([
            'login' => 'Akun Anda dinonaktifkan. Hubungi administrator.',
        ]);
        $this->assertGuest();
    }

    public function test_login_record_tanpa_peran_ditolak(): void
    {
        $sikepUser = $this->fakeSikepUser(104);
        User::factory()->create(['sikep_user_id' => 104, 'username' => 'uji104', 'role' => null, 'aktif' => true]);
        $this->mockSikepAttempt($sikepUser);

        $response = $this->from('/login')->post('/login', ['login' => 'uji104', 'password' => 'rahasia']);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors([
            'login' => 'Akun Anda belum memiliki peran. Hubungi administrator.',
        ]);
        $this->assertGuest();
    }

    public function test_kegagalan_integrasi_sikep_tidak_membuat_sesi(): void
    {
        $this->mock(SikepAuthService::class, function ($m) {
            $m->shouldReceive('attempt')->once()->andThrow(
                new QueryException('sikep', 'select 1', [], new \Exception('connection refused'))
            );
        });

        $response = $this->from('/login')->post('/login', ['login' => 'uji', 'password' => 'x']);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors([
            'login' => 'Integrasi SIKEP tidak tersedia saat ini. Coba lagi nanti.',
        ]);
        $this->assertGuest();
    }

    public function test_validasi_field_wajib(): void
    {
        $response = $this->from('/login')->post('/login', ['login' => '', 'password' => '']);

        $response->assertSessionHasErrors(['login', 'password']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
