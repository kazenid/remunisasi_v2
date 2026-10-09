<?php

use App\Http\Controllers\Admin\ApproverMappingController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\IncentiveConfigController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\PeriodController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IndividualTargetController;
use App\Http\Controllers\Kabid\ReviewController;
use App\Http\Controllers\Katimker\TaskController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RecapController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// ─── Guest Routes (redirect to dashboard if already auth) ──────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

// ─── 2FA Challenge (dapat diakses saat pending-2FA, belum Auth::login) ──────
Route::get('/two-factor/challenge', [TwoFactorController::class, 'challenge'])
    ->name('two-factor.challenge');
Route::post('/two-factor/challenge', [TwoFactorController::class, 'verifyChallenge'])
    ->middleware('throttle:two-factor')
    ->name('two-factor.challenge.verify');

// ─── Auth Routes ─────────────────────────────────────────────────────────
// Urutan: auth → active-role → two-factor → role:. EnsureActiveRole berjalan
// SEBELUM two-factor agar user nonaktif/tanpa peran di-logout lebih dulu.
Route::middleware(['auth', 'active-role', 'two-factor'])->group(function () {
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Logout
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // ─── 2FA Setup / Enrollment (admin sudah login tapi dipaksa enroll) ─────
    Route::get('/two-factor/setup', [TwoFactorController::class, 'setup'])
        ->name('two-factor.setup');
    Route::post('/two-factor/setup', [TwoFactorController::class, 'confirmSetup'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.setup.confirm');
    Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.recovery-codes.regenerate');

    // Akses bukti terlindungi (otorisasi diperketat pada Tugas 11).
    Route::get('/bukti/{attachment}', [AttachmentController::class, 'show'])->name('bukti.show');

    // ─── Admin ────────────────────────────────────────────────────────────
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/pemetaan', [ApproverMappingController::class, 'index'])->name('pemetaan.index');
        Route::post('/pemetaan', [ApproverMappingController::class, 'store'])->name('pemetaan.store');
        Route::get('/rekap', [RecapController::class, 'admin'])->name('rekap.index');
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('/pengguna', [UserController::class, 'index'])->name('pengguna.index');
        Route::post('/pengguna/tarik', [UserController::class, 'tarik'])->name('pengguna.tarik');
        Route::post('/pengguna/{user}/role', [UserController::class, 'ubahRole'])->name('pengguna.role');
        Route::post('/pengguna/{user}/aktif', [UserController::class, 'toggleAktif'])->name('pengguna.aktif');
        Route::get('/periode', [PeriodController::class, 'index'])->name('periode.index');
        Route::post('/periode', [PeriodController::class, 'store'])->name('periode.store');
        Route::post('/periode/{periode}', [PeriodController::class, 'update'])->name('periode.update');
        Route::get('/integrasi', [IntegrationController::class, 'index'])->name('integrasi.index');
        Route::get('/konfigurasi-insentif', [IncentiveConfigController::class, 'index'])->name('insentif.index');
        Route::post('/konfigurasi-insentif', [IncentiveConfigController::class, 'update'])->name('insentif.update');
        Route::post('/target-individu', [IndividualTargetController::class, 'store'])->name('target.store');
    });

    // ─── Katimker ─────────────────────────────────────────────────────────
    Route::middleware('role:katimker')->prefix('katimker')->name('katimker.')->group(function () {
        Route::get('/tugas', [TaskController::class, 'index'])->name('tugas.index');
        Route::get('/tugas/create', [TaskController::class, 'create'])->name('tugas.create');
        Route::post('/tugas', [TaskController::class, 'store'])->name('tugas.store');
        Route::get('/tugas/{tugas}/edit', [TaskController::class, 'edit'])->name('tugas.edit');
        Route::post('/tugas/{tugas}', [TaskController::class, 'update'])->name('tugas.update');
        Route::post('/tugas/{tugas}/ajukan', [TaskController::class, 'submit'])->name('tugas.submit');
        Route::delete('/tugas/{tugas}', [TaskController::class, 'destroy'])->name('tugas.destroy');
        Route::delete('/lampiran/{attachment}', [TaskController::class, 'destroyAttachment'])->name('tugas.lampiran.destroy');
        Route::get('/rekap', [RecapController::class, 'katimker'])->name('rekap.index');
    });

    // ─── Kabid ────────────────────────────────────────────────────────────
    Route::middleware('role:kabid')->prefix('kabid')->name('kabid.')->group(function () {
        Route::get('/penilaian', [ReviewController::class, 'index'])->name('penilaian.index');
        Route::get('/penilaian/{tugas}', [ReviewController::class, 'show'])->name('penilaian.show');
        Route::post('/penilaian/{tugas}/setujui', [ReviewController::class, 'approve'])->name('penilaian.approve');
        Route::post('/penilaian/{tugas}/tolak', [ReviewController::class, 'reject'])->name('penilaian.reject');
        Route::post('/penilaian/{tugas}/jenis', [ReviewController::class, 'ubahJenis'])->name('penilaian.jenis');
        Route::get('/penugasan', [ReviewController::class, 'assignForm'])->name('penugasan.create');
        Route::post('/penugasan', [ReviewController::class, 'assignStore'])->name('penugasan.store');
        Route::post('/target-individu', [IndividualTargetController::class, 'store'])->name('target.store');
        Route::get('/rekap', [RecapController::class, 'kabid'])->name('rekap.index');
    });

    // ─── Products CRUD ────────────────────────────────────────────────────
    Route::resource('products', ProductController::class)->except(['create', 'edit', 'show'])
        ->names([
            'index' => 'pages.crud',
            'store' => 'products.store',
            'update' => 'products.update',
            'destroy' => 'products.destroy',
        ]);

    // ─── UIKit Pages ──────────────────────────────────────────────────────
    Route::prefix('uikit')->name('uikit.')->group(function () {
        Route::get('/formlayout', fn () => Inertia::render('UIKit/FormLayout'))->name('formlayout');
        Route::get('/input', fn () => Inertia::render('UIKit/InputDoc'))->name('input');
        Route::get('/button', fn () => Inertia::render('UIKit/ButtonDoc'))->name('button');
        Route::get('/table', fn () => Inertia::render('UIKit/TableDoc'))->name('table');
        Route::get('/list', fn () => Inertia::render('UIKit/ListDoc'))->name('list');
        Route::get('/tree', fn () => Inertia::render('UIKit/TreeDoc'))->name('tree');
        Route::get('/panel', fn () => Inertia::render('UIKit/PanelsDoc'))->name('panel');
        Route::get('/overlay', fn () => Inertia::render('UIKit/OverlayDoc'))->name('overlay');
        Route::get('/media', fn () => Inertia::render('UIKit/MediaDoc'))->name('media');
        Route::get('/menu', fn () => Inertia::render('UIKit/MenuDoc'))->name('menu');
        Route::get('/message', fn () => Inertia::render('UIKit/MessagesDoc'))->name('message');
        Route::get('/file', fn () => Inertia::render('UIKit/FileDoc'))->name('file');
        Route::get('/charts', fn () => Inertia::render('UIKit/ChartDoc'))->name('charts');
        Route::get('/timeline', fn () => Inertia::render('UIKit/TimelineDoc'))->name('timeline');
        Route::get('/misc', fn () => Inertia::render('UIKit/MiscDoc'))->name('misc');
    });

    // ─── Blocks ───────────────────────────────────────────────────────────
    Route::get('/blocks/free', fn () => Inertia::render('UIKit/BlocksFree'))->name('blocks.free');

    // ─── Other Pages ──────────────────────────────────────────────────────
    Route::prefix('pages')->name('pages.')->group(function () {
        Route::get('/empty', fn () => Inertia::render('Pages/Empty'))->name('empty');
        Route::get('/notfound', fn () => Inertia::render('Pages/NotFound'))->name('notfound');
    });

    Route::get('/start/documentation', fn () => Inertia::render('Pages/Documentation'))->name('documentation');
});

// ─── Auth Error Pages (accessible without auth) ──────────────────────────
Route::get('/auth/access', fn () => Inertia::render('Auth/Access'))->name('auth.access');
Route::get('/auth/error', fn () => Inertia::render('Auth/Error'))->name('auth.error');
