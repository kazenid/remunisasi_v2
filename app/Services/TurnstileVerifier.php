<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifikasi token Cloudflare Turnstile di server. Bila kunci belum
 * dikonfigurasi, CAPTCHA dianggap nonaktif (lingkungan dev/test).
 */
class TurnstileVerifier
{
    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    public function siteKey(): ?string
    {
        return $this->enabled() ? (string) config('services.turnstile.site_key') : null;
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(8)->post(self::ENDPOINT, array_filter([
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (Throwable $e) {
            report($e);

            return false; // fail-closed: tanpa verifikasi, login ditolak.
        }

        return $response->ok() && $response->json('success') === true;
    }
}
