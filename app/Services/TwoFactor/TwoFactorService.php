<?php

namespace App\Services\TwoFactor;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * Membungkus pragmarx/google2fa untuk kebutuhan 2FA aplikasi.
 * SIKEP services tidak disentuh.
 */
class TwoFactorService
{
    /** Alfabet recovery code tanpa karakter ambigu (0,1,O,I). */
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Base32 secret plaintext (disimpan pemanggil lewat mutator terenkripsi).
     */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * otpauth://totp/{issuer}:{label}?secret=...&issuer=...&algorithm=SHA1&digits=6&period=30
     */
    public function buildOtpauthUri(string $secret, string $accountLabel): string
    {
        $issuer = (string) config('twofactor.issuer');

        return 'otpauth://totp/'
            .rawurlencode($issuer).':'.rawurlencode($accountLabel)
            .'?secret='.$secret
            .'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * Render SVG QR dari URI otpauth (tanpa gd/imagick). Mengembalikan markup <svg>.
     */
    public function qrSvg(string $otpauthUri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($otpauthUri);
    }

    /**
     * Verifikasi kode TOTP terhadap window config. Guard defensif: kode kosong /
     * non-6-digit mengembalikan FALSE secara diam (tanpa melempar). Pesan
     * user-facing dimiliki validasi controller.
     */
    public function verify(?string $secret, string $code): bool
    {
        if ($secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        return $this->google2fa->verifyKey($secret, $code, (int) config('twofactor.window'));
    }

    /**
     * N recovery code acak, unik, format XXXX-XXXX. Alfabet kustom tanpa 0/1/O/I
     * via random_int (bukan Str::random, agar jaminan pengecualian terjaga).
     *
     * @return array<int, string>
     */
    public function generateRecoveryCodes(): array
    {
        $count = (int) config('twofactor.recovery_code_count');
        $codes = [];

        while (count($codes) < $count) {
            $code = $this->randomGroup().'-'.$this->randomGroup();
            $codes[$code] = true; // dedup via key
            if (count($codes) >= $count) {
                break;
            }
        }

        return array_keys($codes);
    }

    private function randomGroup(): string
    {
        $max = strlen(self::RECOVERY_ALPHABET) - 1;
        $group = '';
        for ($i = 0; $i < 4; $i++) {
            $group .= self::RECOVERY_ALPHABET[random_int(0, $max)];
        }

        return $group;
    }
}
