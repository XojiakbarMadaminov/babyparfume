<?php

namespace App\Http\Controllers\Mobile;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\MobileSalesStatisticsService;
use App\Http\Requests\Mobile\SalesStatisticsRequest;

class SalesStatisticsController extends Controller
{
    public function __invoke(SalesStatisticsRequest $request, MobileSalesStatisticsService $statistics): JsonResponse
    {
        return response()->json(['data' => $statistics->report(
            $request->user()->current_store_id,
            $request->validated(),
            true,
        )])->header('Cache-Control', 'no-store, private');
    }
}
