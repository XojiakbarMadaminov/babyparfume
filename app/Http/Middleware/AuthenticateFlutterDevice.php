<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\FlutterDevice;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFlutterDevice
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Auth::guard('flutter')->forgetUser();
        $user  = $request->user('flutter');
        $token = $user?->currentAccessToken();

        if (!$request->bearerToken() || !$token instanceof PersonalAccessToken || !$token->can('flutter:access')) {
            return response()->json([
                'error' => ['code' => 'unauthenticated', 'message' => 'Qurilmaga qayta kiring.'],
            ], 401);
        }

        $device = FlutterDevice::query()
            ->where('user_id', $user->getKey())
            ->where('personal_access_token_id', $token->getKey())
            ->whereNull('revoked_at')
            ->first();

        if (!$device) {
            return response()->json([
                'error' => ['code' => 'device_revoked', 'message' => 'Qurilma sessiyasi bekor qilingan.'],
            ], 401);
        }

        $request->attributes->set('flutter_device', $device);
        $request->setUserResolver(fn (?string $guard = null): ?Authenticatable => $guard ? Auth::guard($guard)->user() : $user);

        return $next($request);
    }
}
