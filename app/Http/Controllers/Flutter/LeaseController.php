<?php

namespace App\Http\Controllers\Flutter;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class LeaseController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user   = $request->user();
        $store  = $request->attributes->get('flutter_store');
        $device = $request->attributes->get('flutter_device');

        $now                 = now();
        $offlineMaxSeconds   = (int) config('flutter.offline_max_seconds', 36000);
        $leaseExpiry         = $now->copy()->addSeconds($offlineMaxSeconds);
        $tokenCreatedAt      = $user->currentAccessToken()?->created_at;
        $tokenLifetimeMinutes = (int) config('flutter.token_lifetime_minutes', 43200);

        if ($tokenCreatedAt) {
            $tokenExpiry = $tokenCreatedAt->copy()->addMinutes($tokenLifetimeMinutes);
            if ($tokenExpiry->lessThan($leaseExpiry)) {
                $leaseExpiry = $tokenExpiry;
            }
        }

        return response()->json([
            'data' => [
                'lease_id'            => Str::uuid()->toString(),
                'store_id'            => (string) $store->id,
                'user_id'             => (string) $user->id,
                'device_uuid'         => (string) $device->device_uuid,
                'server_time'         => $now->toISOString(),
                'issued_at'           => $now->toISOString(),
                'offline_until'       => $leaseExpiry->toISOString(),
                'offline_max_seconds' => $offlineMaxSeconds,
            ],
        ]);
    }
}
