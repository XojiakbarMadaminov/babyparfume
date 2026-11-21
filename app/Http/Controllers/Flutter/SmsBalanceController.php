<?php

namespace App\Http\Controllers\Flutter;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\SmsBalanceService;
use App\Http\Controllers\Controller;

class SmsBalanceController extends Controller
{
    public function show(Request $request, SmsBalanceService $balances): JsonResponse
    {
        abort_unless($request->user()->canViewSmsBalance(), 403);

        return response()->json(['data' => $balances->getBalance()]);
    }

    public function refresh(Request $request, SmsBalanceService $balances): JsonResponse
    {
        abort_unless($request->user()->canViewSmsBalance(), 403);

        return response()->json(['data' => $balances->getBalance(refresh: true)]);
    }
}
