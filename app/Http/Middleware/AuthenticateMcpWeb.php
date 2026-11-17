<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpWeb
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::guard('web')->check()) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        $request->setUserResolver(fn () => Auth::guard('web')->user());

        return $next($request);
    }
}
