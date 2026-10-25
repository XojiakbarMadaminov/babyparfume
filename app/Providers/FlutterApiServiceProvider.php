<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use App\Http\Middleware\EnsureFlutterApiEnabled;

class FlutterApiServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        RateLimiter::for('flutter-login', function (Request $request): array {
            $email = $request->input('email');

            return [
                Limit::perMinute(6)->by('flutter-login-ip:' . $request->ip()),
                Limit::perMinute(6)->by('flutter-login-account:' . hash('sha256', (is_string($email) ? strtolower($email) : 'invalid') . '|' . $request->ip())),
            ];
        });
        RateLimiter::for('flutter-api', fn (Request $request): Limit => Limit::perMinute(120)->by(
            'flutter-api:' . ($request->user('flutter')?->currentAccessToken()?->getKey() ?? $request->ip())
        ));

        if (!$this->app->routesAreCached()) {
            Route::middleware(['api', \App\Http\Middleware\EnsurePrintingEnabled::class])
                ->prefix('api/print-agent/v1')->name('print-agent.')
                ->group(base_path('routes/print-agent.php'));
            Route::middleware(['api', EnsureFlutterApiEnabled::class])
                ->prefix('api/flutter/v1')
                ->name('flutter.')
                ->group(base_path('routes/flutter.php'));
        }
    }
}
