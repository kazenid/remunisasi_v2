<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penegakan sesi berjalan: user yang dinonaktifkan atau kehilangan peran di
 * tengah sesi di-logout dan diarahkan ke login pada request berikutnya.
 *
 * Dipasang pada SELURUH grup `auth`, SEBELUM `two-factor` dan `role:`, sehingga
 * dashboard pun tercakup. Mengecualikan route logout & jalur 2FA agar user
 * nonaktif tetap bisa keluar dan jalur 2FA tidak macet.
 */
class EnsureActiveRole
{
    /** Pesan tunggal (sumber kebenaran teks) — NFR1. */
    public const MESSAGE = 'Akun Anda dinonaktifkan atau tidak memiliki peran. Hubungi administrator.';

    /** Route yang tidak boleh dicegat agar logout & jalur 2FA tetap jalan. */
    private const EXCEPT = [
        'logout',
        'two-factor.challenge',
        'two-factor.setup',
        'two-factor.setup.confirm',
        'two-factor.recovery-codes.regenerate',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request); // 'auth' yang menangani tamu
        }

        if (in_array($request->route()?->getName(), self::EXCEPT, true)) {
            return $next($request);
        }

        // Fail-closed: roles() membaca role lokal. Nonaktif / role=null
        // => roles=[] => tidak berhak melanjutkan sesi.
        if ($user->roles()['roles'] === []) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['login' => self::MESSAGE]);
        }

        return $next($request);
    }
}
