<?php

namespace App\Http\Controllers\Flutter;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\MobileSalesStatisticsService;
use App\Http\Requests\Flutter\SalesStatisticsRequest;

class SalesStatisticsController extends Controller
{
    public function __invoke(SalesStatisticsRequest $request, MobileSalesStatisticsService $statistics): JsonResponse
    {
        $store = $request->attributes->get('flutter_store');

        $report = $statistics->report(
            (int) $store->id,
            $request->validated(),
            true,
            true,
        );

        return response()->json([
            'data' => [
                'store_id'        => (string) $store->id,
                'period'          => (string) ($report['filter']['period'] ?? ''),
                'start'           => (string) ($report['filter']['start'] ?? ''),
                'end'             => (string) ($report['filter']['end'] ?? ''),
                'sales_amount'    => (string) (int) ($report['salesAmount'] ?? 0),
                'sales_count'     => (int) ($report['salesCount'] ?? 0),
                'average_receipt' => (string) (int) ($report['averageReceipt'] ?? 0),
                'profit'          => (string) (int) ($report['profit'] ?? 0),
                'margin_percent'  => $report['marginPercent'] !== null ? round((float) $report['marginPercent'], 2) : null,
                'change_percent'  => $report['changePercent'] !== null ? round((float) $report['changePercent'], 2) : null,
                'currency'        => 'UZS',
                'trend'           => $report['trend'] ?? [],
                'payments'        => collect($report['payments'] ?? [])->map(fn ($p): array => [
                    'key'    => (string) ($p['key'] ?? ''),
                    'name'   => (string) ($p['label'] ?? ''),
                    'amount' => (string) (int) ($p['amount'] ?? 0),
                ])->all(),
                'top_products' => collect($report['topProducts'] ?? [])->map(fn ($tp): array => [
                    'name'     => (string) ($tp['name'] ?? ''),
                    'quantity' => (int) ($tp['quantity'] ?? 0),
                    'amount'   => (string) (int) ($tp['amount'] ?? 0),
                ])->all(),
            ],
        ]);
    }
}
