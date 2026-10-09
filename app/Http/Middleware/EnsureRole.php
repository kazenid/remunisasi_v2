<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Otorisasi per-route: pastikan peran user termasuk yang diizinkan untuk route ini.
 * Pemakaian route: ->middleware('role:admin') atau 'role:katimker,kabid'.
 *
 * Penegakan "akun masih aktif & punya peran" (logout saat roles=[]) ditangani
 * lebih dulu oleh EnsureActiveRole; di sini cukup abort(403) untuk peran tak cocok.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $userRoles = $user->roles()['roles'];

        if (count(array_intersect($roles, $userRoles)) === 0) {
            abort(403); // punya peran, tapi bukan yang diizinkan untuk route ini
        }

        return $next($request);
    }
}
