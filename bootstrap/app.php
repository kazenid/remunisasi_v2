<?php

use App\Http\Middleware\EnsureActiveRole;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'active-role' => EnsureActiveRole::class,
            'two-factor' => EnsureTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Body POST yang melebihi post_max_size tidak melewati jalur validasi
        // ($_POST/$_FILES kosong), jadi tangani di sini agar request web mendapat
        // pesan Indonesia yang jelas (satu toast via flash error) alih-alih 413 polos.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->is('api/*')) {
                return null; // biarkan perilaku default untuk API
            }

            return back()->with('error', 'Ukuran total unggahan melebihi batas server. Perkecil atau kurangi berkas (maks 5 MB per foto selfie, 10 MB per dokumen).');
        });
    })->create();
