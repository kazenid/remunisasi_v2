<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
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
                'user' => $request->user() ? [
                    'id'                 => $request->user()->id,
                    'nip'                => $request->user()->nip,
                    'name'               => $request->user()->name,
                    'nama_lengkap'       => $request->user()->nama_lengkap,
                    'email'              => $request->user()->email,
                    'role'               => $request->user()->role,
                    'id_unit'            => $request->user()->id_unit,
                    'nama_unit'          => $request->user()->nama_unit,
                    'is_katimker'        => (bool) $request->user()->is_katimker,
                    'atasan_langsung_id' => $request->user()->atasan_langsung_id,
                ] : null,
            ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info'    => fn () => $request->session()->get('info'),
            ],
        ]);
    }
}
