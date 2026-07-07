<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// ─── Guest Routes (redirect to dashboard if already auth) ──────────────
Route::middleware('guest')->group(function () {
    Route::get('/login',    [LoginController::class, 'create'])->name('login');
    Route::post('/login',   [LoginController::class, 'store']);
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
});

// ─── Auth Routes ─────────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Logout
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // ─── Products CRUD ────────────────────────────────────────────────────
    Route::resource('products', ProductController::class)->except(['create', 'edit', 'show'])
        ->names([
            'index'   => 'pages.crud',
            'store'   => 'products.store',
            'update'  => 'products.update',
            'destroy' => 'products.destroy',
        ]);

    // ─── UIKit Pages ──────────────────────────────────────────────────────
    Route::prefix('uikit')->name('uikit.')->group(function () {
        Route::get('/formlayout', fn () => Inertia::render('UIKit/FormLayout'))->name('formlayout');
        Route::get('/input',      fn () => Inertia::render('UIKit/InputDoc'))->name('input');
        Route::get('/button',     fn () => Inertia::render('UIKit/ButtonDoc'))->name('button');
        Route::get('/table',      fn () => Inertia::render('UIKit/TableDoc'))->name('table');
        Route::get('/list',       fn () => Inertia::render('UIKit/ListDoc'))->name('list');
        Route::get('/tree',       fn () => Inertia::render('UIKit/TreeDoc'))->name('tree');
        Route::get('/panel',      fn () => Inertia::render('UIKit/PanelsDoc'))->name('panel');
        Route::get('/overlay',    fn () => Inertia::render('UIKit/OverlayDoc'))->name('overlay');
        Route::get('/media',      fn () => Inertia::render('UIKit/MediaDoc'))->name('media');
        Route::get('/menu',       fn () => Inertia::render('UIKit/MenuDoc'))->name('menu');
        Route::get('/message',    fn () => Inertia::render('UIKit/MessagesDoc'))->name('message');
        Route::get('/file',       fn () => Inertia::render('UIKit/FileDoc'))->name('file');
        Route::get('/charts',     fn () => Inertia::render('UIKit/ChartDoc'))->name('charts');
        Route::get('/timeline',   fn () => Inertia::render('UIKit/TimelineDoc'))->name('timeline');
        Route::get('/misc',       fn () => Inertia::render('UIKit/MiscDoc'))->name('misc');
    });

    // ─── Blocks ───────────────────────────────────────────────────────────
    Route::get('/blocks/free', fn () => Inertia::render('UIKit/BlocksFree'))->name('blocks.free');

    // ─── Other Pages ──────────────────────────────────────────────────────
    Route::prefix('pages')->name('pages.')->group(function () {
        Route::get('/empty',    fn () => Inertia::render('Pages/Empty'))->name('empty');
        Route::get('/notfound', fn () => Inertia::render('Pages/NotFound'))->name('notfound');
    });

    Route::get('/start/documentation', fn () => Inertia::render('Pages/Documentation'))->name('documentation');
});

// ─── Auth Error Pages (accessible without auth) ──────────────────────────
Route::get('/auth/access', fn () => Inertia::render('Auth/Access'))->name('auth.access');
Route::get('/auth/error',  fn () => Inertia::render('Auth/Error'))->name('auth.error');
