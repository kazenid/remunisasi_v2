<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveRole;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use App\Services\TwoFactor\TwoFactorService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $service,
        private readonly RoleResolver $roleResolver,
    ) {}

    /**
     * GET two-factor.challenge — tampilkan halaman tantangan.
     */
    public function challenge(Request $request): Response|RedirectResponse
    {
        // User sudah login penuh (ketik URL challenge manual) → dashboard, bukan login.
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        // Tiket masuk sah satu-satunya: pending state dari LoginController::store().
        if ($request->session()->get('2fa.pending_user_id') === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    /**
     * POST two-factor.challenge.verify — proses tantangan (throttled).
     */
    public function verifyChallenge(Request $request): RedirectResponse
    {
        $pendingId = $request->session()->get('2fa.pending_user_id');

        if ($pendingId === null) {
            return redirect()->route('login');
        }

        $user = User::find($pendingId);

        if ($user === null) {
            $request->session()->forget(['2fa.pending_user_id', '2fa.remember']);

            return redirect()->route('login');
        }

        // Guard integritas: secret null / status 2FA di-reset out-of-band saat pending.
        if ($user->two_factor_secret === null || ! $user->hasConfirmedTwoFactor()) {
            $request->session()->forget(['2fa.pending_user_id', '2fa.remember']);

            return redirect()->route('login');
        }

        $data = $request->validate([
            'mode' => 'required|in:totp,recovery',
            'code' => ['required', 'string', Rule::when(
                $request->input('mode') === 'totp',
                'regex:/^\d{6}$/',
                'regex:/^[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}$/',
            )],
        ]);

        try {
            $ok = $data['mode'] === 'totp'
                ? $this->service->verify($user->two_factor_secret, $data['code'])
                : $user->consumeRecoveryCode($data['code']);
        } catch (DecryptException $e) {
            // PRE-login: user belum Auth::login → jatuhkan ke login, bukan setup.
            report($e);
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
            $request->session()->forget(['2fa.pending_user_id', '2fa.remember']);

            return redirect()->route('login')
                ->withErrors(['login' => 'Konfigurasi 2FA Anda perlu diatur ulang. Hubungi administrator.']);
        }

        if (! $ok) {
            throw ValidationException::withMessages([
                'code' => 'Kode verifikasi tidak valid.',
            ]);
        }

        // Guard fail-closed: user dinonaktifkan / role=null di tengah pending-2FA
        // tidak boleh menyelesaikan challenge. Ditempatkan SETELAH kode valid agar
        // tidak membocorkan status akun, tetapi SEBELUM Auth::login().
        if ($this->roleResolver->resolve($user->sikep_user_id)['roles'] === []) {
            $request->session()->forget(['2fa.pending_user_id', '2fa.remember']);

            return redirect()->route('login')->withErrors([
                'login' => EnsureActiveRole::MESSAGE,
            ]);
        }

        Auth::login($user, (bool) $request->session()->get('2fa.remember'));
        $request->session()->forget(['2fa.pending_user_id', '2fa.remember']);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * GET two-factor.setup — tampilkan halaman enrollment.
     */
    public function setup(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Hanya admin WAJIB-2FA yang BELUM konfirmasi yang boleh melihat halaman ini.
        // Admin terkonfirmasi di-redirect SEBELUM secret dibaca → tidak membocorkan
        // ulang faktor kedua yang masih aktif.
        if ($user === null || ! $user->requiresTwoFactor() || $user->hasConfirmedTwoFactor()) {
            return redirect()->route('dashboard');
        }

        try {
            $secret = $user->two_factor_secret;
        } catch (DecryptException $e) {
            // POST-login: draft korup (APP_KEY dirotasi) → buang & bangkitkan ulang.
            report($e);
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
            $secret = null;
        }

        if ($secret === null) {
            $user->two_factor_secret = $this->service->generateSecret();
            $user->save();
            $secret = $user->two_factor_secret;
        }

        $otpauthUri = $this->service->buildOtpauthUri($secret, (string) $user->username);
        $svg = $this->service->qrSvg($otpauthUri);

        return Inertia::render('Auth/TwoFactorSetup', [
            'qrSvg' => $svg,
            'secret' => $secret, // base32 plaintext, HANYA untuk admin unconfirmed yang enroll
        ]);
    }

    /**
     * POST two-factor.setup.confirm — konfirmasi enrollment (throttled).
     */
    public function confirmSetup(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->requiresTwoFactor() || $user->hasConfirmedTwoFactor()) {
            return redirect()->route('dashboard');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        try {
            $ok = $this->service->verify($user->two_factor_secret, $data['code']);
        } catch (DecryptException $e) {
            // POST-login: draft rusak → reset & re-enroll di setup().
            report($e);
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            return redirect()->route('two-factor.setup');
        }

        if (! $ok) {
            throw ValidationException::withMessages([
                'code' => 'Kode verifikasi tidak valid.',
            ]);
        }

        $recovery = $this->service->generateRecoveryCodes();
        $user->two_factor_recovery_codes = $recovery;
        $user->two_factor_confirmed_at = now();
        $user->save();

        // Recovery codes ditampilkan SEKALI via Inertia::render langsung (bukan
        // redirect()->with — share() hanya mengekspos 4 key flash hardcoded).
        return Inertia::render('Auth/TwoFactorSetup', [
            'recoveryCodes' => $recovery, // plaintext, HANYA pada respons ini
            'justConfirmed' => true,
        ]);
    }

    /**
     * POST two-factor.recovery-codes.regenerate — buat ulang recovery codes (throttled).
     */
    public function regenerateRecoveryCodes(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->requiresTwoFactor() || ! $user->hasConfirmedTwoFactor()) {
            return redirect()->route('dashboard');
        }

        $recovery = $this->service->generateRecoveryCodes();
        $user->two_factor_recovery_codes = $recovery;
        $user->save();

        return Inertia::render('Auth/TwoFactorSetup', [
            'recoveryCodes' => $recovery,
            'justConfirmed' => true,
        ]);
    }
}
