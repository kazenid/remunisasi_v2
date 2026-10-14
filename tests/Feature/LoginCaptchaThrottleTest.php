<?php

namespace Tests\Feature;

use App\Models\Sikep\SikepUser;
use App\Models\User;
use App\Services\Sikep\SikepAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Pembatasan percobaan login dan CAPTCHA Cloudflare Turnstile. */
class LoginCaptchaThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function sikepUser(int $id = 101): SikepUser
    {
        $u = new SikepUser;
        $u->id = $id;
        $u->username = 'uji'.$id;
        $u->no_absen = 900000 + $id;
        $u->nama = 'PENGGUNA UJI';
        $u->aktif = 1;

        return $u;
    }

    private function aktifkanTurnstile(): void
    {
        config()->set('services.turnstile.site_key', 'site-key');
        config()->set('services.turnstile.secret_key', 'secret-key');
    }

    public function test_akun_dikunci_setelah_lima_kali_gagal(): void
    {
        $this->mock(SikepAuthService::class, function ($m) {
            $m->shouldReceive('attempt')->times(5)->andReturnNull();
        });

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'uji101', 'password' => 'salah'])
                ->assertSessionHasErrors('login');
        }

        // Percobaan ke-6 diblokir sebelum menyentuh SIKEP (attempt hanya 5x).
        $this->post('/login', ['login' => 'uji101', 'password' => 'salah'])
            ->assertSessionHasErrors('login');
        $this->assertStringContainsString('Terlalu banyak percobaan', session('errors')->first('login'));
    }

    public function test_login_sukses_membersihkan_hitungan_gagal(): void
    {
        User::factory()->katimker()->create(['sikep_user_id' => 101, 'username' => 'uji101']);
        $this->mock(SikepAuthService::class, function ($m) {
            $m->shouldReceive('attempt')->andReturn(null, null, $this->sikepUser());
        });

        $this->post('/login', ['login' => 'uji101', 'password' => 'x']);
        $this->post('/login', ['login' => 'uji101', 'password' => 'x']);
        $this->post('/login', ['login' => 'uji101', 'password' => 'benar'])->assertRedirect(route('dashboard'));

        $this->assertSame(0, RateLimiter::attempts('login:uji101|127.0.0.1'));
    }

    public function test_tanpa_kunci_turnstile_captcha_nonaktif(): void
    {
        $this->get('/login')->assertInertia(fn ($page) => $page->where('turnstileSiteKey', null));
    }

    public function test_halaman_login_mengirim_site_key_bila_aktif(): void
    {
        $this->aktifkanTurnstile();

        $this->get('/login')->assertInertia(fn ($page) => $page->where('turnstileSiteKey', 'site-key'));
    }

    public function test_token_captcha_kosong_ditolak_sebelum_cek_sikep(): void
    {
        $this->aktifkanTurnstile();
        $this->mock(SikepAuthService::class, function ($m) {
            $m->shouldNotReceive('attempt');
        });

        $this->post('/login', ['login' => 'uji101', 'password' => 'x'])
            ->assertSessionHasErrors('login');
    }

    public function test_token_captcha_tidak_valid_ditolak(): void
    {
        $this->aktifkanTurnstile();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false], 200)]);
        $this->mock(SikepAuthService::class, function ($m) {
            $m->shouldNotReceive('attempt');
        });

        $this->post('/login', ['login' => 'uji101', 'password' => 'x', 'turnstile_token' => 'bad'])
            ->assertSessionHasErrors('login');
    }

    public function test_token_captcha_valid_melanjutkan_login(): void
    {
        $this->aktifkanTurnstile();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true], 200)]);
        User::factory()->katimker()->create(['sikep_user_id' => 101, 'username' => 'uji101']);
        $this->mock(SikepAuthService::class, function ($m) {
            $m->shouldReceive('attempt')->once()->andReturn($this->sikepUser());
        });

        $this->post('/login', ['login' => 'uji101', 'password' => 'benar', 'turnstile_token' => 'ok'])
            ->assertRedirect(route('dashboard'));

        Http::assertSentCount(1);
    }

    public function test_gangguan_jaringan_verifikasi_captcha_fail_closed(): void
    {
        $this->aktifkanTurnstile();
        Http::fake(['challenges.cloudflare.com/*' => fn () => throw new ConnectionException('down')]);
        $this->mock(SikepAuthService::class, function ($m) {
            $m->shouldNotReceive('attempt');
        });

        $this->post('/login', ['login' => 'uji101', 'password' => 'x', 'turnstile_token' => 'ok'])
            ->assertSessionHasErrors('login');
    }
}
