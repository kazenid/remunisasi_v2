<?php

namespace Tests\Feature;

use App\Models\Sikep\SikepUser;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use App\Services\Sikep\SikepAuthService;
use App\Services\TwoFactor\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Feature test cabang 2FA pada LoginController + TwoFactorController::verifyChallenge.
 * SikepAuthService & RoleResolver di-mock seperti LoginTest.
 */
class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSikepUser(int $id = 200): SikepUser
    {
        $u = new SikepUser;
        $u->id = $id;
        $u->username = 'uji'.$id;
        $u->no_absen = 900000 + $id;
        $u->nama = 'PENGGUNA UJI';
        $u->gelar_depan = null;
        $u->gelar_belakang = null;
        $u->aktif = 1;

        return $u;
    }

    private function mockRoles(bool $isAdmin): void
    {
        $this->mock(RoleResolver::class, function ($m) use ($isAdmin) {
            $m->shouldReceive('resolve')->andReturn([
                'roles' => $isAdmin ? ['admin'] : ['katimker'],
                'is_katimker' => ! $isAdmin,
                'is_kabid' => false,
                'is_admin' => $isAdmin,
            ]);
        });
    }

    private function realSecret(): string
    {
        return app(TwoFactorService::class)->generateSecret();
    }

    public function test_admin_terkonfirmasi_tidak_login_dan_diarahkan_ke_challenge(): void
    {
        $sikepUser = $this->fakeSikepUser(201);

        // Row user sudah ada dengan 2FA terkonfirmasi.
        $user = User::factory()->admin()->create(['sikep_user_id' => 201, 'username' => 'uji201']);
        $user->two_factor_secret = $this->realSecret();
        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->mock(SikepAuthService::class, function ($m) use ($sikepUser) {
            $m->shouldReceive('attempt')->once()->andReturn($sikepUser);
        });
        $this->mockRoles(true);

        $response = $this->post('/login', ['login' => 'uji201', 'password' => 'rahasia']);

        $response->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->assertSame($user->id, session('2fa.pending_user_id'));
    }

    public function test_admin_first_login_terkonfirmasi_tetap_ke_challenge(): void
    {
        // Row user BARU dibuat pada login ini, is_admin resolve true, 2FA terkonfirmasi
        // (disimulasikan: syncFromSikep membuat row, lalu kita set confirmed via DB sebelum login).
        // Untuk membuktikan anti-bypass, buat row terlebih dahulu dengan confirmed.
        $sikepUser = $this->fakeSikepUser(202);
        $user = User::factory()->admin()->create(['sikep_user_id' => 202, 'username' => 'uji202']);
        $user->two_factor_secret = $this->realSecret();
        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->mock(SikepAuthService::class, function ($m) use ($sikepUser) {
            $m->shouldReceive('attempt')->once()->andReturn($sikepUser);
        });
        $this->mockRoles(true);

        $response = $this->post('/login', ['login' => 'uji202', 'password' => 'rahasia']);

        $response->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_non_admin_login_langsung_dashboard(): void
    {
        $sikepUser = $this->fakeSikepUser(203);
        // Gerbang login baru mewajibkan record lokal aktif ber-peran.
        User::factory()->katimker()->create(['sikep_user_id' => 203, 'username' => 'uji203']);

        $this->mock(SikepAuthService::class, function ($m) use ($sikepUser) {
            $m->shouldReceive('attempt')->once()->andReturn($sikepUser);
        });
        $this->mockRoles(false);

        $response = $this->post('/login', ['login' => 'uji203', 'password' => 'rahasia']);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertNull(session('2fa.pending_user_id'));
    }

    public function test_admin_unconfirmed_login_tapi_dipaksa_setup(): void
    {
        $sikepUser = $this->fakeSikepUser(204);
        User::factory()->admin()->create(['sikep_user_id' => 204, 'username' => 'uji204']);

        $this->mock(SikepAuthService::class, function ($m) use ($sikepUser) {
            $m->shouldReceive('attempt')->once()->andReturn($sikepUser);
        });
        $this->mockRoles(true);

        $response = $this->post('/login', ['login' => 'uji204', 'password' => 'rahasia']);

        // Login berhasil (admin unconfirmed), tapi intended dashboard akan di-redirect
        // oleh middleware ke setup. Login redirect sendiri ke dashboard.
        $this->assertAuthenticated();

        // Request berikutnya ke dashboard di-redirect ke setup oleh EnsureTwoFactor.
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
    }

    public function test_verify_challenge_totp_valid_login(): void
    {
        $secret = $this->realSecret();
        $user = User::factory()->admin()->create(['sikep_user_id' => 205]);
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->save();

        $otp = (new Google2FA)->getCurrentOtp($secret);

        $response = $this->withSession([
            '2fa.pending_user_id' => $user->id,
            '2fa.remember' => false,
        ])->post(route('two-factor.challenge.verify'), [
            'mode' => 'totp',
            'code' => $otp,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('2fa.pending_user_id'));
    }

    public function test_verify_challenge_totp_salah_tetap_guest(): void
    {
        $secret = $this->realSecret();
        $user = User::factory()->admin()->create(['sikep_user_id' => 206]);
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->save();

        $response = $this->withSession([
            '2fa.pending_user_id' => $user->id,
            '2fa.remember' => false,
        ])->from(route('two-factor.challenge'))
            ->post(route('two-factor.challenge.verify'), [
                'mode' => 'totp',
                'code' => '000000',
            ]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_verify_challenge_recovery_valid_login_dan_terkonsumsi(): void
    {
        $secret = $this->realSecret();
        $user = User::factory()->admin()->create(['sikep_user_id' => 207]);
        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = ['AB12-CD34', 'EF56-GH78'];
        $user->two_factor_confirmed_at = now();
        $user->save();

        $response = $this->withSession([
            '2fa.pending_user_id' => $user->id,
            '2fa.remember' => false,
        ])->post(route('two-factor.challenge.verify'), [
            'mode' => 'recovery',
            'code' => 'AB12-CD34',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['EF56-GH78'], $user->fresh()->two_factor_recovery_codes);

        // Login ulang pakai kode sama → gagal.
        $this->post(route('logout'));
        $response2 = $this->withSession([
            '2fa.pending_user_id' => $user->id,
            '2fa.remember' => false,
        ])->from(route('two-factor.challenge'))
            ->post(route('two-factor.challenge.verify'), [
                'mode' => 'recovery',
                'code' => 'AB12-CD34',
            ]);

        $response2->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_verify_challenge_guard_secret_null(): void
    {
        $user = User::factory()->admin()->create(['sikep_user_id' => 208]);
        // secret null meski confirmed_at di-set (simulasi reset out-of-band parsial).
        $user->two_factor_confirmed_at = now();
        $user->save();

        $response = $this->withSession([
            '2fa.pending_user_id' => $user->id,
            '2fa.remember' => false,
        ])->post(route('two-factor.challenge.verify'), [
            'mode' => 'totp',
            'code' => '123456',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertNull(session('2fa.pending_user_id'));
        $this->assertGuest();
    }

    public function test_verify_challenge_user_dinonaktifkan_saat_pending_ditolak(): void
    {
        // Admin 2FA terkonfirmasi, lalu dinonaktifkan SELAMA pending-2FA.
        $secret = $this->realSecret();
        $user = User::factory()->admin()->create(['sikep_user_id' => 210]);
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->save();
        $user->update(['aktif' => false]); // dinonaktifkan di tengah pending

        $otp = (new Google2FA)->getCurrentOtp($secret);

        $response = $this->withSession([
            '2fa.pending_user_id' => $user->id,
            '2fa.remember' => false,
        ])->from(route('two-factor.challenge'))
            ->post(route('two-factor.challenge.verify'), [
                'mode' => 'totp',
                'code' => $otp,
            ]);

        // Guard fail-closed: kode valid pun tidak menghasilkan login.
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->assertNull(session('2fa.pending_user_id'));
    }

    public function test_challenge_user_terautentikasi_penuh_redirect_dashboard(): void
    {
        $this->mockRoles(false);
        $user = User::factory()->create(['sikep_user_id' => 209]);

        $response = $this->actingAs($user)->get(route('two-factor.challenge'));

        $response->assertRedirect(route('dashboard'));
    }

    public function test_challenge_tanpa_pending_redirect_login(): void
    {
        $response = $this->get(route('two-factor.challenge'));

        $response->assertRedirect(route('login'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
