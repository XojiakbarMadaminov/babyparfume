<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Carbon;
use App\Models\InventoryAdjustment;
use Illuminate\Database\Eloquent\Builder;

class MobileSalesStatisticsService
{
    /** @param array{period: string, start: string, end: string} $filter */
    public function report(int $storeId, array $filter, bool $canViewTopProducts): array
    {
        $start       = Carbon::parse($filter['start'])->startOfDay();
        $end         = Carbon::parse($filter['end'])->endOfDay();
        $sales       = $this->sales($storeId, $start, $end);
        $summary     = (clone $sales)->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(total_amount), 0) as amount')->first();
        $grossAmount = (float) $summary->amount;
        $count       = (int) $summary->sales_count;
        $profit      = (float) SaleItem::query()->whereIn('sale_id', (clone $sales)->select('id'))
            ->selectRaw('COALESCE(SUM((sale_items.price - sale_items.unit_cost) * sale_items.quantity), 0) as profit')
            ->value('profit');
        $adjustments = $this->adjustments($storeId, $start, $end);
        $netAmount   = $grossAmount + $adjustments['revenue'];
        $profit += $adjustments['profit'];
        $days           = (int) $start->diffInDays($end->copy()->startOfDay()) + 1;
        $previousEnd    = $start->copy()->subDay()->endOfDay();
        $previousStart  = $start->copy()->subDays($days);
        $previousAmount = (float) $this->sales($storeId, $previousStart, $previousEnd)->sum('total_amount')
            + $this->adjustments($storeId, $previousStart, $previousEnd)['revenue'];

        return [
            'filter'         => ['storeId' => $storeId, ...$filter],
            'salesAmount'    => $netAmount,
            'salesCount'     => $count,
            'averageReceipt' => $count > 0 ? $grossAmount / $count : 0,
            'profit'         => $profit,
            'marginPercent'  => $netAmount > 0 ? $profit / $netAmount * 100 : null,
            'changePercent'  => $previousAmount > 0 ? ($netAmount - $previousAmount) / $previousAmount * 100 : null,
            'trend'          => $this->trend($sales, $start, $end, $filter['period'] === 'today'),
            'payments'       => $this->payments($sales, $grossAmount),
            'topProducts'    => $canViewTopProducts ? $this->topProducts($sales) : [],
        ];
    }

    private function sales(int $storeId, Carbon $start, Carbon $end): Builder
    {
        return Sale::query()->where('store_id', $storeId)->completed()->whereBetween('created_at', [$start, $end]);
    }

    /** @return array{revenue: float, profit: float} */
    private function adjustments(int $storeId, Carbon $start, Carbon $end): array
    {
        $rows = InventoryAdjustment::query()
            ->join('products', 'products.id', '=', 'inventory_adjustments.product_id')
            ->where('products.store_id', $storeId)
            ->whereBetween('inventory_adjustments.created_at', [$start, $end])
            ->selectRaw('adjustment_type, SUM(unit_price * ABS(inventory_adjustments.quantity)) as revenue, SUM(COALESCE(inventory_adjustments.unit_cost, 0) * ABS(inventory_adjustments.quantity)) as cost')
            ->groupBy('adjustment_type')->get();
        $revenue = 0.0;
        $profit  = 0.0;
        foreach ($rows as $row) {
            $direction = $row->adjustment_type === InventoryAdjustment::TYPE_EXCHANGE_OUT ? 1 : -1;
            $revenue += $direction * (float) $row->revenue;
            $profit += $direction * ((float) $row->revenue - (float) $row->cost);
        }

        return ['revenue' => $revenue, 'profit' => $profit];
    }

    private function trend(Builder $sales, Carbon $start, Carbon $end, bool $hourly): array
    {
        $hourExpression = $sales->getModel()->getConnection()->getDriverName() === 'pgsql'
            ? "TO_CHAR(created_at, 'HH24')"
            : 'SUBSTR(created_at, 12, 2)';
        $expression = $hourly ? $hourExpression : 'DATE(created_at)';
        $amounts    = (clone $sales)->selectRaw("$expression as bucket, SUM(total_amount) as amount")
            ->groupBy('bucket')->pluck('amount', 'bucket');
        $points = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key      = $cursor->format($hourly ? 'H' : 'Y-m-d');
            $points[] = ['label' => $cursor->format($hourly ? 'H:00' : 'd.m'), 'amount' => (float) ($amounts[$key] ?? 0)];
            if ($hourly) {
                $cursor->addHour();
            } else {
                $cursor->addDay();
            }
        }

        return $points;
    }

    private function payments(Builder $sales, float $grossAmount): array
    {
        $groups = (clone $sales)->selectRaw('payment_type, SUM(total_amount) as amount, SUM(paid_amount) as paid, SUM(remaining_amount) as remaining, SUM(mixed_cash_amount) as cash, SUM(mixed_card_amount) as card')
            ->groupBy('payment_type')->get();
        $amounts = ['cash' => 0.0, 'card' => 0.0, 'transfer' => 0.0, 'debt' => 0.0, 'partial' => 0.0, 'other' => 0.0];
        foreach ($groups as $group) {
            if ($group->payment_type === 'mixed') {
                $amounts['cash'] += (float) $group->cash;
                $amounts['card'] += (float) $group->card;
            } elseif ($group->payment_type === 'partial') {
                $amounts['partial'] += (float) $group->paid;
                $amounts['debt'] += (float) $group->remaining;
            } else {
                $key = array_key_exists($group->payment_type ?? '', $amounts) ? $group->payment_type : 'other';
                $amounts[$key] += (float) $group->amount;
            }
        }
        $labels   = ['cash' => 'Naqd', 'card' => 'Karta', 'transfer' => 'O‘tkazma', 'debt' => 'Qarz', 'partial' => 'Qisman to‘langan', 'other' => 'Boshqa'];
        $payments = [];
        foreach ($amounts as $key => $amount) {
            if ($amount != 0) {
                $payments[] = ['key' => $key, 'label' => $labels[$key], 'amount' => $amount, 'percent' => $grossAmount > 0 ? $amount / $grossAmount * 100 : 0];
            }
        }

        return $payments;
    }

    private function topProducts(Builder $sales): array
    {
        return SaleItem::query()->whereIn('sale_id', (clone $sales)->select('id'))
            ->with(['product' => fn ($query) => $query->withTrashed()])
            ->selectRaw('product_id, SUM(quantity) as quantity_sold, SUM(total) as amount')
            ->groupBy('product_id')->orderByDesc('quantity_sold')->orderBy('product_id')->limit(10)->get()
            ->map(fn (SaleItem $item): array => [
                'id'       => $item->product_id,
                'name'     => $item->product?->name ?? 'Noma’lum mahsulot',
                'quantity' => (float) $item->quantity_sold,
                'amount'   => (float) $item->amount,
            ])->all();
    }
}
