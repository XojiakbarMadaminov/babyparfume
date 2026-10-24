<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Client;
use App\Models\ProductStock;
use Illuminate\Validation\Rule;
use App\Models\ProductSizeStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FlutterSaleService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createSale(User $user, Store $store, array $payload): array
    {
        Validator::make($payload, [
            'local_sale_id'            => ['required', 'uuid'],
            'items'                    => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id'       => ['required', 'integer', 'min:1'],
            'items.*.product_revision' => ['nullable', 'integer', 'min:1'],
            'items.*.stock_id'         => ['required', 'integer', 'min:1'],
            'items.*.product_size_id'  => ['nullable', 'integer', 'min:1'],
            'items.*.quantity'         => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items.*.unit_price'       => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.discount'         => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'receipt_discount'         => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'payment.type'             => ['required', Rule::in(['cash', 'card', 'transfer', 'mixed', 'partial', 'debt', 'preorder'])],
            'payment.cash'             => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'payment.card'             => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'client.server_id'         => ['nullable', 'integer', 'min:1'],
            'payment.note'             => ['nullable', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($user, $store, $payload): array {
            if (Sale::withoutGlobalScope('current_store')->where('flutter_local_sale_id', $payload['local_sale_id'])->exists()) {
                throw ValidationException::withMessages(['local_sale_id' => 'Bu lokal chek avval yuborilgan. Avvalgi amal UUID sini ishlating.']);
            }
            $paymentType     = $payload['payment']['type'] ?? 'cash';
            $receiptDiscount = (float) ($payload['receipt_discount'] ?? 0);
            $items           = $payload['items'] ?? [];

            if (empty($items)) {
                throw ValidationException::withMessages(['items' => 'Savdo ro‘yxati bo‘sh bo‘lishi mumkin emas.']);
            }

            $totalAmount = 0.0;
            $itemsData   = [];

            foreach ($items as $item) {
                $productId    = (int) $item['product_id'];
                $product      = app(FlutterSyncService::class)->productsQuery($store->id)->findOrFail($productId);
                $costSnapshot = null;
                if (isset($item['product_revision'])) {
                    $costSnapshot = \App\Models\FlutterSyncRevision::query()->where('store_id', $store->id)
                        ->where('entity_type', 'product')->where('entity_id', $productId)->where('revision', $item['product_revision'])
                        ->whereNotNull('payload->unit_cost')->first();
                    if (!$costSnapshot) {
                        throw ValidationException::withMessages(['product_revision' => 'Mahsulotning saqlangan narx versiyasi topilmadi. Administrator tekshiruvi kerak.']);
                    }
                }
                $costData     = $costSnapshot?->payload ?? [];
                $unitCost     = (float) ($costData['unit_cost'] ?? $product->initial_price);
                $catalogPrice = (float) ($costData['catalog_price'] ?? $product->price);

                $stock = Stock::withoutGlobalScope('current_store')->whereHas('stores', fn ($query) => $query->whereKey($store->id))
                    ->whereKey($item['stock_id'])->first();
                if (!$stock) {
                    throw ValidationException::withMessages(['stock_id' => 'Ombor ushbu do‘konga tegishli emas.']);
                }
                $sizeId = $item['product_size_id'] ?? null;
                if (($product->isSizeBased() && !$sizeId) || ($sizeId && !$product->sizes()->whereKey($sizeId)->exists())) {
                    throw ValidationException::withMessages(['product_size_id' => 'Mahsulot razmeri noto‘g‘ri.']);
                }

                $unitPrice = (float) $item['unit_price'];
                $qty       = (int) $item['quantity'];
                $discount  = (float) ($item['discount'] ?? 0);
                $rowTotal  = max(0.0, ($unitPrice * $qty) - $discount);
                if (($discount > 0 || abs($unitPrice - $catalogPrice) > 0.001)
                    && ($rowTotal / $qty) < round($unitCost * 1.05, 2)) {
                    throw ValidationException::withMessages(['unit_price' => 'Narx minimal narxdan past. Administrator tekshiruvi kerak.']);
                }
                if ($discount > $unitPrice * $qty) {
                    throw ValidationException::withMessages(['discount' => 'Chegirma mahsulot summasidan oshmasin.']);
                }
                $totalAmount += $rowTotal;

                $itemsData[] = [
                    'product'         => $product,
                    'product_size_id' => !empty($item['product_size_id']) ? (int) $item['product_size_id'] : null,
                    'stock_id'        => (int) $item['stock_id'],
                    'quantity'        => $qty,
                    'unit_price'      => $unitPrice,
                    'unit_cost'       => $unitCost,
                    'total'           => $rowTotal,
                ];
            }

            if ($receiptDiscount > $totalAmount) {
                throw ValidationException::withMessages(['receipt_discount' => 'Chegirma chek summasidan oshmasin.']);
            }
            $finalTotal = round($totalAmount - $receiptDiscount, 2);
            if ($receiptDiscount > 0 && $totalAmount > 0) {
                $allocated = 0.0;
                $lastIndex = count($itemsData) - 1;
                foreach ($itemsData as $index => &$row) {
                    $lineTotal = $index === $lastIndex ? round($finalTotal - $allocated, 2)
                        : round($row['total'] * $finalTotal / $totalAmount, 2);
                    $row['total']      = $lineTotal;
                    $row['unit_price'] = $lineTotal / $row['quantity'];
                    $allocated += $lineTotal;
                }
                unset($row);
            } else {
                foreach ($itemsData as &$row) {
                    $row['unit_price'] = $row['total'] / $row['quantity'];
                }
                unset($row);
            }
            $cashAmount = (float) ($payload['payment']['cash'] ?? 0);
            $cardAmount = (float) ($payload['payment']['card'] ?? 0);

            $paidAmount = match ($paymentType) {
                'cash'     => $finalTotal,
                'card'     => $finalTotal,
                'transfer' => $finalTotal,
                'mixed'    => $cashAmount + $cardAmount,
                'partial'  => $cashAmount + $cardAmount,
                'debt'     => 0.0,
                'preorder' => 0.0,
                default    => $finalTotal,
            };

            $remainingAmount = max(0.0, $finalTotal - $paidAmount);
            if ($remainingAmount > 0 && $paymentType !== 'preorder' && abs($remainingAmount - round($remainingAmount)) > 0.001) {
                throw ValidationException::withMessages(['payment' => 'Qarz summasi butun so‘m bo‘lishi kerak.']);
            }
            if (($paymentType === 'mixed' && abs($paidAmount - $finalTotal) > 0.001)
                || ($paymentType === 'partial' && ($paidAmount <= 0 || $paidAmount >= $finalTotal))) {
                throw ValidationException::withMessages(['payment' => 'To‘lov summasi savdo summasiga mos emas.']);
            }

            // Handle Debtor if debt remains
            $clientId = !empty($payload['client']['server_id']) ? (int) $payload['client']['server_id'] : null;
            if (!$clientId && !in_array($paymentType, ['cash', 'card'], true)) {
                throw ValidationException::withMessages(['client' => 'Qarzli savdo uchun mijoz tanlanishi shart.']);
            }
            if ($clientId) {
                Client::query()->findOrFail($clientId);
            }

            $sale = Sale::query()->create([
                'store_id'              => $store->id,
                'client_id'             => $clientId,
                'cart_id'               => (int) (now()->format('U') % 2147483647),
                'total_amount'          => $finalTotal,
                'paid_amount'           => $paidAmount,
                'remaining_amount'      => $remainingAmount,
                'payment_type'          => $paymentType,
                'flutter_local_sale_id' => $payload['local_sale_id'],
                'status'                => $paymentType === 'preorder' ? Sale::STATUS_PENDING : Sale::STATUS_COMPLETED,
                'created_by'            => $user->id,
                'mixed_cash_amount'     => $paymentType === 'mixed' ? $cashAmount : 0,
                'mixed_card_amount'     => $paymentType === 'mixed' ? $cardAmount : 0,
                'created_at'            => $payload['occurred_at'] ?? now(),
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
                    $stockRow = ProductStock::query()
                        ->where('product_id', $itemRow['product']->id)
                        ->where('product_size_id', $itemRow['product_size_id'])
                        ->where('stock_id', $itemRow['stock_id'])
                        ->lockForUpdate()
                        ->first();

                    if (!$stockRow) {
                        $stockRow = ProductSizeStock::query()->where('product_size_id', $itemRow['product_size_id'])->where('stock_id', $itemRow['stock_id'])->lockForUpdate()->first();
                    }
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
                    } else {
                        $deficits[] = ['product_id' => $itemRow['product']->id, 'requested' => $itemRow['quantity'], 'available' => 0];
                    }
                } else {
                    $stockRow = ProductStock::query()
                        ->where('product_id', $itemRow['product']->id)
                        ->whereNull('product_size_id')
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
                    } else {
                        $deficits[] = ['product_id' => $itemRow['product']->id, 'requested' => $itemRow['quantity'], 'available' => 0];
                    }
                }
            }

            $debtor   = null;
            $debtNote = filled($payload['payment']['note'] ?? null) ? $payload['payment']['note'] : 'Savdo #' . $sale->id . ' bo‘yicha qarz';
            // Record debt on Debtor model if needed
            if ($remainingAmount > 0 && $clientId && $paymentType !== 'preorder') {
                $debtor = app(SaleDebtService::class)->record(
                    $sale,
                    $remainingAmount,
                    $debtNote,
                    mobileUserId: $user->id,
                    mobileRequestId: $payload['local_sale_id'],
                    occurredAt: $sale->created_at,
                    preferredDebtorId: isset($payload['debtor_context']['id']) ? (int) $payload['debtor_context']['id'] : null,
                );
            }

            return [
                'server_sale_id'   => (string) $sale->id,
                'client_id'        => $clientId ? (string) $clientId : null,
                'debtor_id'        => $debtor ? (string) $debtor->id : null,
                'debtor_revision'  => $debtor ? (string) app(FlutterSyncService::class)->entityRevision($store->id, 'debtor', $debtor->id) : null,
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
