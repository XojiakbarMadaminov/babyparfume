<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileSalesSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $mobileRequest = $request->routeIs('mobile.sales-workspace');
        if ($request->routeIs('*livewire.update') && $request->isMethod('POST') && $request->hasHeader('X-Livewire')) {
            foreach ((array) $request->input('components', []) as $component) {
                $snapshot = is_array($component) && is_string($component['snapshot'] ?? null)
                    ? json_decode($component['snapshot'], true)
                    : null;
                if (($snapshot['memo']['name'] ?? null) === 'mobile.sales') {
                    $mobileRequest = true;
                }
            }
        }
        if (!$mobileRequest) {
            return $next($request);
        }

        $user = $request->user();
        abort_unless($user?->current_store_id && $user->stores()->whereKey($user->current_store_id)->exists(), 403);
        $session   = $request->session();
        $namespace = 'mobile_sales.' . $user->id . '.' . $user->current_store_id;
        $keys      = ['pos_carts', 'pos_active_cart_id', 'pos_cart_clients', 'pos_cart_payment_types', 'pos_cart_partial_payments', 'pos_cart_mixed_payments', 'pos_cart_sale_without_client', 'pos_cart_sale_without_client_payment_type'];
        $original  = [];

        foreach ($keys as $key) {
            if ($session->exists($key)) {
                $original[$key] = $session->get($key);
            }
            $session->forget($key);
            if ($session->exists($namespace . '.' . $key)) {
                $session->put($key, $session->get($namespace . '.' . $key));
            }
        }

        try {
            return $next($request)->header('Cache-Control', 'no-store, private');
        } finally {
            foreach ($keys as $key) {
                if ($session->exists($key)) {
                    $session->put($namespace . '.' . $key, $session->get($key));
                } else {
                    $session->forget($namespace . '.' . $key);
                }
                $session->forget($key);
                if (array_key_exists($key, $original)) {
                    $session->put($key, $original[$key]);
                }
            }
        }
    }
}
