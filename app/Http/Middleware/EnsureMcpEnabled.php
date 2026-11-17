<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMcpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('mcp.enabled'), 404);
        if ($request->hasHeader('Origin')) {
            $allowed = [rtrim((string) config('app.url'), '/'), ...config('mcp.allowed_origins', [])];
            abort_unless(in_array($request->header('Origin'), $allowed, true), 403);
        }
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
        abort_if((int) $request->header('Content-Length', 0) > 65536 || strlen($request->getContent()) > 65536, 413);
        if (app()->isProduction()) {
            abort_unless($request->isSecure(), 400, 'HTTPS talab qilinadi.');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $formAction  = "'self'";
        $callbackUri = $request->attributes->get('mcp_oauth_callback_uri');
        if (is_string($callbackUri) && ($origin = $this->callbackOrigin($callbackUri)) !== null) {
            $formAction .= ' ' . $origin;
        }
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; form-action {$formAction}");

        return $response;
    }

    private function callbackOrigin(string $uri): ?string
    {
        $parts = parse_url($uri);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || !preg_match('/\A(?:[A-Za-z0-9.-]+|\[[A-Fa-f0-9:]+\])\z/', $parts['host'] ?? '')) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
