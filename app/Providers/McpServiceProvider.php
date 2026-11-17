<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Laravel\Mcp\Facades\Mcp;
use App\Mcp\Servers\PosServer;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use App\Http\Middleware\AuthenticateMcp;
use Illuminate\Cache\RateLimiting\Limit;
use App\Http\Middleware\EnsureMcpEnabled;
use App\Http\Middleware\LogMcpTokenRequest;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passport::ignoreRoutes();
    }

    public function boot(): void
    {
        Passport::tokensCan(['mcp:use' => 'Oson POS MCP ulanishi']);
        Passport::defaultScopes(['mcp:use']);
        Passport::tokensExpireIn(now()->addMinutes((int) config('mcp.access_token_minutes')));
        Passport::refreshTokensExpireIn(now()->addDays((int) config('mcp.refresh_token_days')));
        Passport::authorizationView('mcp.authorize');

        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(60)->by('mcp:' . ($request->attributes->get('mcp_connection')?->id ?? $request->ip())));
        RateLimiter::for('mcp-oauth', fn (Request $request): Limit => Limit::perMinute(20)->by('mcp-oauth:' . $request->ip()));
        RateLimiter::for('mcp-register', fn (Request $request): array => [
            Limit::perMinute(5)->by('mcp-register:' . $request->ip()),
            Limit::perDay(100)->by('mcp-register-day:' . $request->ip()),
        ]);

        if (!$this->app->routesAreCached()) {
            Route::middleware([LogMcpTokenRequest::class, EnsureMcpEnabled::class])->group(function (): void {
                Mcp::web('/mcp', PosServer::class)
                    ->withoutMiddleware(AddWwwAuthenticateHeader::class)
                    ->middleware([AuthenticateMcp::class, 'throttle:mcp'])
                    ->name('mcp.endpoint');
                require base_path('routes/mcp.php');
            });
        }
    }
}
