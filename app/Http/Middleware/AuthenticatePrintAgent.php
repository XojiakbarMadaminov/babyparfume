<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\PrintAgent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePrintAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        abort_unless($token && strlen($token) === 64, 401, 'Agentni qayta ulang.');
        $agent = PrintAgent::query()->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')->whereNotNull('active_store_id')->first();
        abort_unless($agent, 401, 'Agent bekor qilingan.');
        $request->attributes->set('print_agent', $agent);

        return $next($request);
    }
}
