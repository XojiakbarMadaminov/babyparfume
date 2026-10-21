<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\ProductStock;
use App\Models\ProductSizeStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FlutterSaleService
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSale(User $user, Store $store, array $payload): array
    {
        return DB::transaction(function () use ($user, $store, $payload): array {
            $paymentType     = $payload['payment']['type'] ?? 'cash';
            $receiptDiscount = (float) ($payload['receipt_discount'] ?? 0);
            $items           = $payload['items'] ?? [];

            if (empty($items)) {
                throw ValidationException::withMessages(['items' => 'Savdo ro‘yxati bo‘sh bo‘lishi mumkin emas.']);
            }

            $totalAmount = 0.0;
            $itemsData   = [];

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $product   = Product::withoutGlobalScope('current_store')
                    ->where('store_id', $store->id)
                    ->findOrFail($productId);

                $unitPrice = (float) $item['unit_price'];
                $qty       = (int) $item['quantity'];
                $discount  = (float) ($item['discount'] ?? 0);
                $rowTotal  = max(0.0, ($unitPrice * $qty) - $discount);
                $totalAmount += $rowTotal;

                $itemsData[] = [
                    'product'         => $product,
                    'product_size_id' => !empty($item['product_size_id']) ? (int) $item['product_size_id'] : null,
                    'stock_id'        => (int) $item['stock_id'],
                    'quantity'        => $qty,
                    'unit_price'      => $unitPrice,
                    'unit_cost'       => (float) $product->initial_price,
                    'total'           => $rowTotal,
                ];
            }

            $finalTotal = max(0.0, $totalAmount - $receiptDiscount);
            $cashAmount = (float) ($payload['payment']['cash'] ?? 0);
            $cardAmount = (float) ($payload['payment']['card'] ?? 0);

            $paidAmount = match ($paymentType) {
                'cash'     => $finalTotal,
                'card'     => $finalTotal,
                'transfer' => $finalTotal,
                'mixed'    => $cashAmount + $cardAmount,
                'partial'  => $cashAmount + $cardAmount,
                'debt'     => 0.0,
                default    => $finalTotal,
            };

            $remainingAmount = max(0.0, $finalTotal - $paidAmount);

            // Handle Debtor if debt remains
            $clientId = !empty($payload['client']['server_id']) ? (int) $payload['client']['server_id'] : null;
            if ($remainingAmount > 0 && !$clientId) {
                throw ValidationException::withMessages(['client' => 'Qarzli savdo uchun mijoz tanlanishi shart.']);
            }

            $sale = Sale::query()->create([
                'store_id'         => $store->id,
                'client_id'        => $clientId,
                'cart_id'          => (int) (now()->format('U') % 2147483647),
                'total_amount'     => $finalTotal,
                'paid_amount'      => $paidAmount,
                'remaining_amount' => $remainingAmount,
                'payment_type'     => $paymentType,
            ]);

            $deficits = [];

            foreach ($itemsData as $itemRow) {
                $sale->items()->create([
                    'product_id'      => $itemRow['product']->id,
                    'stock_id'        => $itemRow['stock_id'],
                    'product_size_id' => $itemRow['product_size_id'],
                    'quantity'        => $itemRow['quantity'],
                    'price'           => $itemRow['unit_price'],
                    'unit_cost'       => $itemRow['unit_cost'],
                    'total'           => $itemRow['total'],
                ]);

                // Stock update with R-05 (zero-stock checkout support)
                if (!empty($itemRow['product_size_id'])) {
                    $stockRow = ProductSizeStock::query()
                        ->where('product_size_id', $itemRow['product_size_id'])
                        ->where('stock_id', $itemRow['stock_id'])
                        ->lockForUpdate()
                        ->first();

                    if ($stockRow) {
                        $available = (int) $stockRow->quantity;
                        $deduct    = min($available, $itemRow['quantity']);
                        if ($deduct < $itemRow['quantity']) {
                            $deficits[] = [
                                'product_id' => $itemRow['product']->id,
                                'size_id'    => $itemRow['product_size_id'],
                                'requested'  => $itemRow['quantity'],
                                'available'  => $available,
                            ];
                        }
                        $stockRow->update(['quantity' => max(0, $available - $itemRow['quantity'])]);
                    }
                } else {
                    $stockRow = ProductStock::query()
                        ->where('product_id', $itemRow['product']->id)
                        ->where('stock_id', $itemRow['stock_id'])
                        ->lockForUpdate()
                        ->first();

                    if ($stockRow) {
                        $available = (int) $stockRow->quantity;
                        $deduct    = min($available, $itemRow['quantity']);
                        if ($deduct < $itemRow['quantity']) {
                            $deficits[] = [
                                'product_id' => $itemRow['product']->id,
                                'requested'  => $itemRow['quantity'],
                                'available'  => $available,
                            ];
                        }
                        $stockRow->update(['quantity' => max(0, $available - $itemRow['quantity'])]);
                    }
                }
            }

            // Record debt on Debtor model if needed
            if ($remainingAmount > 0 && $clientId) {
                $client = Client::findOrFail($clientId);
                $debtor = Debtor::withoutGlobalScope('current_store')
                    ->where('store_id', $store->id)
                    ->where('client_id', $client->id)
                    ->first();

                if (!$debtor) {
                    $debtor = Debtor::query()->create([
                        'store_id'  => $store->id,
                        'client_id' => $client->id,
                        'amount'    => (int) $remainingAmount,
                        'currency'  => 'UZS',
                        'date'      => now()->toDateString(),
                        'note'      => 'Savdo #' . $sale->id . ' bo‘yicha qarz',
                    ]);
                } else {
                    $debtor->increment('amount', (int) $remainingAmount);
                }

                $debtor->transactions()->create([
                    'type'                => 'debt',
                    'amount'              => (int) $remainingAmount,
                    'date'                => now(),
                    'note'                => 'Savdo #' . $sale->id . ' bo‘yicha qarz',
                    'mobile_user_id'      => $user->id,
                    'mobile_request_id'   => $payload['local_sale_id'] ?? null,
                ]);
            }

            return [
                'server_sale_id'   => (string) $sale->id,
                'total_amount'     => number_format($finalTotal, 2, '.', ''),
                'paid_amount'      => number_format($paidAmount, 2, '.', ''),
                'remaining_amount' => number_format($remainingAmount, 2, '.', ''),
                'currency'         => 'UZS',
                'scale'            => 2,
                'deficits'         => $deficits,
            ];
        });
    }
}
