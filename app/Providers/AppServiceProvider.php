<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            $key = $request->session()->get('2fa.pending_user_id')
                ?? optional($request->user())->id
                ?? $request->ip();

            return [Limit::perMinute(5)->by((string) $key)];
        });
    }
}
