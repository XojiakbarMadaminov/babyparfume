<?php

namespace App\Http\Controllers\Flutter;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class CapabilitiesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'protocol_version'   => 1,
                'authentication'     => 'bearer',
                'offline_max_seconds' => 36000,
                'server_time'        => now()->toIso8601String(),
                'features'           => [
                    'device_sessions' => true,
                    'products'        => true,
                    'debtors'         => true,
                    'sales'           => true,
                    'sync'            => true,
                    'offline_lease'   => true,
                ],
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
