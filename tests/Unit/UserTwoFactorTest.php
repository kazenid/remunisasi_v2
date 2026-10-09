<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class UserTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_tersimpan_terenkripsi_dan_terbaca_plaintext(): void
    {
        $user = User::factory()->admin()->create();
        $user->two_factor_secret = 'PLAINSECRET123456';
        $user->save();

        // Kolom mentah di DB adalah ciphertext, bukan plaintext.
        $raw = $user->getRawOriginal('two_factor_secret');
        $this->assertNotSame('PLAINSECRET123456', $raw);
        $this->assertSame('PLAINSECRET123456', Crypt::decryptString($raw));

        // Accessor mengembalikan plaintext.
        $this->assertSame('PLAINSECRET123456', $user->fresh()->two_factor_secret);
    }

    public function test_secret_null_tetap_null(): void
    {
        $user = User::factory()->admin()->create();
        $user->two_factor_secret = null;
        $user->save();

        $this->assertNull($user->fresh()->getRawOriginal('two_factor_secret'));
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_recovery_codes_roundtrip_array(): void
    {
        $user = User::factory()->admin()->create();
        $codes = ['AB12-CD34', 'EF56-GH78'];
        $user->two_factor_recovery_codes = $codes;
        $user->save();

        $this->assertSame($codes, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_recovery_codes_kosong_tersimpan_null(): void
    {
        $user = User::factory()->admin()->create();
        $user->two_factor_recovery_codes = ['AB12-CD34'];
        $user->save();

        $user->two_factor_recovery_codes = [];
        $user->save();

        $this->assertNull($user->fresh()->getRawOriginal('two_factor_recovery_codes'));
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_consume_recovery_code_single_use(): void
    {
        $user = User::factory()->admin()->create();
        $user->two_factor_recovery_codes = ['AB12-CD34', 'EF56-GH78'];
        $user->save();

        $this->assertTrue($user->consumeRecoveryCode('AB12-CD34'));
        $this->assertSame(['EF56-GH78'], $user->fresh()->two_factor_recovery_codes);

        // Kode yang sama tidak bisa dipakai lagi.
        $this->assertFalse($user->fresh()->consumeRecoveryCode('AB12-CD34'));
    }

    public function test_consume_recovery_code_normalisasi(): void
    {
        $user = User::factory()->admin()->create();
        $user->two_factor_recovery_codes = ['AB12-CD34'];
        $user->save();

        // Tanpa hubung + huruf kecil + spasi tetap cocok.
        $this->assertTrue($user->consumeRecoveryCode('ab12 cd34'));
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_consume_recovery_code_tanpa_hubung(): void
    {
        $user = User::factory()->admin()->create();
        $user->two_factor_recovery_codes = ['AB12-CD34'];
        $user->save();

        $this->assertTrue($user->consumeRecoveryCode('AB12CD34'));
    }

    public function test_has_confirmed_two_factor(): void
    {
        $user = User::factory()->admin()->create();
        $this->assertFalse($user->hasConfirmedTwoFactor());

        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->assertTrue($user->fresh()->hasConfirmedTwoFactor());
    }
}
