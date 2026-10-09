<?php

namespace Tests\Unit;

use App\Services\TwoFactor\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorServiceTest extends TestCase
{
    private TwoFactorService $service;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->google2fa = new Google2FA;
        $this->service = new TwoFactorService($this->google2fa);
    }

    public function test_verify_menerima_otp_valid(): void
    {
        $secret = $this->service->generateSecret();
        $otp = $this->google2fa->getCurrentOtp($secret);

        $this->assertTrue($this->service->verify($secret, $otp));
    }

    public function test_verify_menolak_kode_salah(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertFalse($this->service->verify($secret, '000000'));
    }

    public function test_verify_non_enam_digit_mengembalikan_false_tanpa_throw(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertFalse($this->service->verify($secret, ''));
        $this->assertFalse($this->service->verify($secret, '123'));
        $this->assertFalse($this->service->verify($secret, 'abcdef'));
        $this->assertFalse($this->service->verify($secret, '1234567'));
    }

    public function test_verify_secret_null_mengembalikan_false(): void
    {
        $this->assertFalse($this->service->verify(null, '123456'));
    }

    public function test_generate_recovery_codes_jumlah_dan_format(): void
    {
        $codes = $this->service->generateRecoveryCodes();

        $this->assertCount(config('twofactor.recovery_code_count'), $codes);
        $this->assertSame(count($codes), count(array_unique($codes)));

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);
            // Tanpa karakter ambigu 0/1/O/I.
            $this->assertDoesNotMatchRegularExpression('/[01OI]/', $code);
        }
    }

    public function test_build_otpauth_uri_memuat_issuer_label_secret(): void
    {
        config()->set('twofactor.issuer', 'Remunisasi Test');
        $uri = $this->service->buildOtpauthUri('SECRET123', 'admin01');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=SECRET123', $uri);
        $this->assertStringContainsString('issuer=Remunisasi%20Test', $uri);
        $this->assertStringContainsString('admin01', $uri);
    }

    public function test_qr_svg_mengembalikan_markup_svg(): void
    {
        $svg = $this->service->qrSvg('otpauth://totp/Test:admin?secret=ABC');

        $this->assertStringContainsString('<svg', $svg);
    }
}
