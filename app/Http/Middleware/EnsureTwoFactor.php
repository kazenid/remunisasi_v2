<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penegakan 2FA wajib untuk admin. Admin terautentikasi yang belum konfirmasi
 * 2FA diarahkan ke setup pada setiap request kecuali rute setup/confirm/logout.
 * Dipasang sebagai middleware ber-alias ('two-factor') pada grup rute auth agar
 * route binding dijamin ter-resolve.
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Tidak login → biarkan auth middleware lain yang menangani.
        if ($user === null) {
            return $next($request);
        }

        // Hanya admin yang terdampak. Non-admin lolos tanpa biaya tambahan.
        if (! $user->requiresTwoFactor()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        // Admin sudah konfirmasi → lolos, KECUALI halaman enrollment itu sendiri
        // (pertahanan berlapis agar secret aktif tidak pernah di-ekspos ulang).
        if ($user->hasConfirmedTwoFactor()) {
            $enrollmentRoutes = ['two-factor.setup', 'two-factor.setup.confirm'];
            if (in_array($routeName, $enrollmentRoutes, true)) {
                return redirect()->route('dashboard');
            }

            return $next($request);
        }

        // Admin BELUM konfirmasi → kecualikan setup/confirm/logout, selain itu paksa ke setup.
        $allowed = ['two-factor.setup', 'two-factor.setup.confirm', 'logout'];
        if (in_array($routeName, $allowed, true)) {
            return $next($request);
        }

        return redirect()->route('two-factor.setup');
    }
}
