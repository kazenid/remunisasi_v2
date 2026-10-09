<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     * These are available in ALL Vue pages via usePage().props
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => fn () => $request->user() ? (function ($u) {
                    $roles = $u->roles();

                    $fotoUrl = ! empty($u->token_pegawai)
                        ? rtrim((string) config('sikep.foto_base_url'), '/').'/'.$u->token_pegawai.'.jpg'
                        : null;

                    return [
                        'id' => $u->id,
                        'sikep_user_id' => $u->sikep_user_id,
                        'nama' => $u->nama,
                        'username' => $u->username,
                        'foto_url' => $fotoUrl,
                        'roles' => $roles['roles'],
                        'is_katimker' => $roles['is_katimker'],
                        'is_kabid' => $roles['is_kabid'],
                        'is_admin' => $roles['is_admin'],
                        // Boolean non-sensitif saja — jangan pernah kirim secret/recovery codes.
                        'two_factor_enabled' => $u->hasConfirmedTwoFactor(),
                        'needs_two_factor_setup' => $roles['is_admin'] && ! $u->hasConfirmedTwoFactor(),
                    ];
                })($request->user()) : null,
            ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'flash' => [
                // Nonce per-request: berubah tiap kunjungan sehingga watcher di sisi klien
                // selalu fire — dua pesan gagal yang IDENTIK beruntun tetap memicu toast.
                'id' => (string) Str::uuid(),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
        ]);
    }
}
