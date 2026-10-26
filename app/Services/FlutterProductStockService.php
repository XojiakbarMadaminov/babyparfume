<?php

namespace App\Services;

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use App\Models\FlutterDevice;
use App\Models\FlutterSyncOperation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\UniqueConstraintViolationException;

class FlutterProductStockService
{
    /** @param array<string, mixed> $data */
    public function receive(User $user, Store $store, FlutterDevice $device, string $productId, array $data): void
    {
        $payload = [
            'stock_id'        => (int) $data['stock_id'],
            'product_size_id' => isset($data['product_size_id']) ? (int) $data['product_size_id'] : null,
            'quantity'        => (int) $data['quantity'],
        ];
        $hash = hash('sha256', json_encode(['product_id' => $productId, ...$payload], JSON_THROW_ON_ERROR));

        try {
            $store->getConnection()->transaction(function () use ($user, $store, $device, $productId, $data, $payload, $hash): void {
                Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
                $existing = FlutterSyncOperation::query()->where('operation_id', $data['request_id'])->first();
                if ($existing) {
                    abort_unless((int) $existing->user_id === (int) $user->id
                        && (int) $existing->store_id === (int) $store->id
                        && (int) $existing->device_id === (int) $device->id
                        && $existing->operation_type === 'product.stock_in'
                        && $existing->status === 'applied'
                        && hash_equals($existing->payload_hash, $hash), 409, 'Bu kirim so‘rovi boshqa ma’lumot bilan yuborilgan.');

                    return;
                }
                $this->addStock($user, $store, $productId, $payload);
                FlutterSyncOperation::query()->create([
                    'operation_id'   => $data['request_id'], 'user_id' => $user->id, 'store_id' => $store->id,
                    'device_id'      => $device->id, 'entity_type' => 'product', 'entity_id' => $productId,
                    'operation_type' => 'product.stock_in', 'payload' => $payload, 'payload_hash' => $hash,
                    'status'         => 'applied', 'response_data' => ['product_id' => $productId],
                    'occurred_at'    => now(), 'applied_at' => now(),
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Kirimni saqlab bo‘lmadi. Qayta urinib ko‘ring.');
        }
    }

    /** @param array{stock_id: int, product_size_id: ?int, quantity: int} $payload */
    private function addStock(User $user, Store $store, string $productId, array $payload): void
    {
        $product = Product::withoutGlobalScope('current_store')->where('store_id', $store->id)
            ->lockForUpdate()->findOrFail($productId);
        Gate::forUser($user)->authorize('update', $product);

        $stock = Stock::withoutGlobalScope('current_store')->active()
            ->whereHas('stores', fn ($stores) => $stores->whereKey($store->id))
            ->find($payload['stock_id']);
        if (!$stock) {
            throw ValidationException::withMessages(['stock_id' => 'Ushbu do‘konning faol omborini tanlang.']);
        }

        if ($product->isSizeBased()) {
            $size = $product->sizes()->find($payload['product_size_id'] ?? null);
            if (!$size) {
                throw ValidationException::withMessages(['product_size_id' => 'Ushbu mahsulotning razmerini tanlang.']);
            }
            if (!$size->productStocks()->exists()) {
                foreach ($size->stocks()->lockForUpdate()->get() as $legacy) {
                    $size->productStocks()->create(['stock_id' => $legacy->stock_id, 'quantity' => $legacy->quantity]);
                }
            }
            $stocks = $size->productStocks();
        } else {
            if (!empty($payload['product_size_id'])) {
                throw ValidationException::withMessages(['product_size_id' => 'Bu mahsulotda razmer mavjud emas.']);
            }
            $stocks = $product->productStocks()->whereNull('product_size_id');
        }

        $row      = $stocks->where('stock_id', $stock->id)->lockForUpdate()->first();
        $quantity = (int) $payload['quantity'];
        if ((int) ($row?->quantity ?? 0) + $quantity > 2147483647) {
            throw ValidationException::withMessages(['quantity' => 'Natijaviy qoldiq juda katta.']);
        }
        if ($row) {
            $row->increment('quantity', $quantity);
        } else {
            $stocks->create(['stock_id' => $stock->id, 'quantity' => $quantity]);
        }
    }
}
