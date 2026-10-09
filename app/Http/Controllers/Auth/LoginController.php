<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use App\Services\Sikep\SikepAuthService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function __construct(
        private readonly SikepAuthService $sikepAuth,
        private readonly RoleResolver $roleResolver,
    ) {}

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            $sikepUser = $this->sikepAuth->attempt($credentials['login'], $credentials['password']);
        } catch (QueryException $e) {
            // Kegagalan koneksi/integrasi SIKEP: jangan buat sesi.
            report($e);
            throw ValidationException::withMessages([
                'login' => 'Integrasi SIKEP tidak tersedia saat ini. Coba lagi nanti.',
            ]);
        }

        if ($sikepUser === null) {
            // Pesan generik: tidak membedakan user tidak ada vs password salah.
            throw ValidationException::withMessages([
                'login' => 'Kredensial yang diberikan tidak cocok.',
            ]);
        }

        // Gerbang record lokal (SATU-SATUNYA penentu pesan 2.2/2.3/2.4). Muat
        // `role` + `aktif` eksplisit agar tak ada lazy-load tambahan.
        $local = User::query()
            ->where('sikep_user_id', $sikepUser->id)
            ->first(['id', 'sikep_user_id', 'role', 'aktif']);

        if ($local === null) {
            // JANGAN syncFromSikep: penarikan hanya oleh Admin lewat menu Kepegawaian.
            throw ValidationException::withMessages([
                'login' => 'Akun Anda belum terdaftar/diaktifkan di aplikasi. Hubungi administrator.',
            ]);
        }

        if ($local->aktif === false) {
            throw ValidationException::withMessages([
                'login' => 'Akun Anda dinonaktifkan. Hubungi administrator.',
            ]);
        }

        if ($local->role === null) {
            throw ValidationException::withMessages([
                'login' => 'Akun Anda belum memiliki peran. Hubungi administrator.',
            ]);
        }

        // Resolve role lokal HANYA untuk cabang 2FA. Setelah lolos gerbang di atas,
        // hasilnya dijamin non-kosong; jaring pengaman defensif tetap fail-closed.
        $roles = $this->roleResolver->resolve($sikepUser->id);

        if ($roles['roles'] === []) {
            throw ValidationException::withMessages([
                'login' => 'Akun Anda belum memiliki peran. Hubungi administrator.',
            ]);
        }

        // Segarkan identitas (nama/username/no_absen/token/last_sync_at). Aman:
        // record sudah dipastikan ada & syncFromSikep tidak menyentuh role/aktif.
        $user = User::syncFromSikep($sikepUser);

        // Cabang 2FA. WAJIB memakai $roles['is_admin'] yang SUDAH di-resolve di atas
        // (satu sumber kebenaran). JANGAN memakai $user->requiresTwoFactor() di sini
        // (lihat Invarian Urutan di desain).
        $isAdmin = $roles['is_admin'];

        if ($isAdmin && $user->hasConfirmedTwoFactor()) {
            // JANGAN login. Simpan pending-2FA state, redirect ke challenge.
            $request->session()->put('2fa.pending_user_id', $user->id);
            $request->session()->put('2fa.remember', $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        // Non-admin, ATAU admin yang belum konfirmasi 2FA → login seperti biasa.
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->forget(['2fa.pending_user_id', '2fa.remember']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
