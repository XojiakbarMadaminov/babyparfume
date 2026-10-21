<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFlutterStore
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $storeId = (string) $request->route('store');

        if (!ctype_digit($storeId) || filter_var($storeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return response()->json([
                'error' => ['code' => 'store_not_found', 'message' => 'Do‘kon topilmadi.'],
            ], 404);
        }

        $store = $request->user()->stores()->whereKey($storeId)->first();

        if (!$store) {
            return response()->json([
                'error' => ['code' => 'store_forbidden', 'message' => 'Bu do‘konga ruxsat yo‘q.'],
            ], 403);
        }

        $request->attributes->set('flutter_store', $store);

        return $next($request);
    }
}
