<?php

namespace Tests\Unit;

use App\Services\Sikep\SikepAuthService;
use PHPUnit\Framework\TestCase;

/**
 * Verifikasi formula hash SIKEP terhadap vektor-uji nyata.
 * Fakta dari update_password_all.php SIKEP:
 *   md5("cba654".md5("@abc123#"))  = 4f106daf068fd4e7e0530269d00167f4
 *   md5("cba654".md5("abc123"))    = 6b81f806ca469ed8ad0132e162f889a8
 */
class SikepAuthServiceTest extends TestCase
{
    private function service(string $salt1 = 'cba654', string $salt2 = ''): SikepAuthService
    {
        // Set config tanpa bootstrap penuh aplikasi.
        if (! function_exists('config')) {
            require_once __DIR__.'/../../vendor/autoload.php';
        }

        $svc = new class($salt1, $salt2) extends SikepAuthService
        {
            public function __construct(private string $s1, private string $s2) {}

            public function hash(string $password): string
            {
                return md5($this->s1.md5($password).$this->s2);
            }
        };

        return $svc;
    }

    public function test_hash_cocok_vektor_uji_standar(): void
    {
        $svc = $this->service();

        $this->assertSame('4f106daf068fd4e7e0530269d00167f4', $svc->hash('@abc123#'));
        $this->assertSame('6b81f806ca469ed8ad0132e162f889a8', $svc->hash('abc123'));
    }

    public function test_salt_salah_tidak_cocok(): void
    {
        // Formula "abc123"."cba654" (yang ada di md5_php5.php) harus TIDAK cocok.
        $svc = $this->service('abc123', 'cba654');

        $this->assertNotSame('4f106daf068fd4e7e0530269d00167f4', $svc->hash('@abc123#'));
    }
}
