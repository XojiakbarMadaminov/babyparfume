<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use Laravel\Passport\Token;
use Illuminate\Http\Request;
use App\Models\McpConnection;
use Laravel\Passport\AccessToken;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->bearerToken()) {
            return $this->unauthenticated();
        }
        $guard = Auth::guard('mcp');
        $guard->forgetUser();
        $oauthUser   = $guard->user();
        $accessToken = $oauthUser?->currentAccessToken();
        $token       = $accessToken instanceof AccessToken && $accessToken->can('mcp:use')
            ? Token::query()->find($accessToken->oauth_access_token_id) : null;
        if (!$token || !$token->can('mcp:use') || $token->revoked || !$token->expires_at || $token->expires_at->isPast()) {
            return $this->unauthenticated();
        }
        $connection = McpConnection::query()->where('user_id', $oauthUser->id)->where('client_id', $token->client_id)->whereNull('revoked_at')->first();
        abort_unless($connection, 403, 'MCP ulanishi bekor qilingan.');
        $user = User::query()->findOrFail($oauthUser->id);
        $request->attributes->set('mcp_connection', $connection);
        $request->attributes->set('mcp_user', $user);
        $request->setUserResolver(fn () => $user);
        $connection->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json(['error' => 'unauthenticated'], 401, [
            'WWW-Authenticate' => 'Bearer resource_metadata="' . route('mcp.resource-metadata') . '"',
        ]);
    }
}
