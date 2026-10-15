<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->current_store_id && $user->stores()->whereKey($user->current_store_id)->exists(), 403, 'Avval ruxsat berilgan do‘konni tanlang.');
        abort_if($request->hasHeader('X-Mobile-Store') && (string) $request->header('X-Mobile-Store') !== (string) $user->current_store_id, 409, 'Do‘kon boshqa oynada o‘zgargan. Sahifani yangilang.');

        return $next($request)->header('Cache-Control', 'no-store, private');
    }
}
