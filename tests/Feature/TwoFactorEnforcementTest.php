<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Sikep\RoleResolver;
use App\Services\TwoFactor\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Feature test EnsureTwoFactor + setup/confirmSetup.
 */
class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function mockAdmin(): void
    {
        $this->mock(RoleResolver::class, function ($m) {
            $m->shouldReceive('resolve')->andReturn([
                'roles' => ['admin'],
                'is_katimker' => false,
                'is_kabid' => false,
                'is_admin' => true,
            ]);
        });
    }

    private function realSecret(): string
    {
        return app(TwoFactorService::class)->generateSecret();
    }

    public function test_admin_unconfirmed_rute_admin_redirect_ke_setup(): void
    {
        $this->mockAdmin();
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('two-factor.setup'));
    }

    public function test_admin_unconfirmed_akses_setup_lolos(): void
    {
        $this->mockAdmin();
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get(route('two-factor.setup'));

        $response->assertOk();
    }

    public function test_logout_tidak_diarahkan_ke_setup(): void
    {
        $this->mockAdmin();
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_terkonfirmasi_membuka_setup_redirect_dashboard(): void
    {
        $this->mockAdmin();
        $user = User::factory()->admin()->create();
        $user->two_factor_secret = $this->realSecret();
        $user->two_factor_confirmed_at = now();
        $user->save();

        $response = $this->actingAs($user)->get(route('two-factor.setup'));

        $response->assertRedirect(route('dashboard'));
    }

    public function test_confirm_setup_valid_menghasilkan_recovery_codes(): void
    {
        $this->mockAdmin();
        $secret = $this->realSecret();
        $user = User::factory()->admin()->create();
        $user->two_factor_secret = $secret;
        $user->save();

        $otp = (new Google2FA)->getCurrentOtp($secret);

        $response = $this->actingAs($user)->post(route('two-factor.setup.confirm'), [
            'code' => $otp,
        ]);

        $response->assertInertia(fn ($page) => $page
            ->component('Auth/TwoFactorSetup')
            ->where('justConfirmed', true)
            ->has('recoveryCodes', config('twofactor.recovery_code_count'))
        );

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_setup_berikutnya_tidak_memuat_recovery_codes(): void
    {
        $this->mockAdmin();
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get(route('two-factor.setup'));

        $response->assertInertia(fn ($page) => $page
            ->component('Auth/TwoFactorSetup')
            ->missing('recoveryCodes')
            ->missing('justConfirmed')
            ->has('qrSvg')
            ->has('secret')
        );
    }

    public function test_confirm_setup_kode_salah_ditolak(): void
    {
        $this->mockAdmin();
        $user = User::factory()->admin()->create();
        $user->two_factor_secret = $this->realSecret();
        $user->save();

        $response = $this->actingAs($user)
            ->from(route('two-factor.setup'))
            ->post(route('two-factor.setup.confirm'), ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
