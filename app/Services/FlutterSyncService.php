<?php

namespace App\Services;

use App\Models\User;
use App\Models\Store;
use App\Models\Debtor;
use App\Models\Product;
use App\Models\FlutterDevice;
use App\Models\FlutterSyncRevision;
use App\Models\FlutterSyncSnapshot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Resources\Flutter\DebtorResource;
use App\Http\Resources\Flutter\ProductResource;

class FlutterSyncService
{
    public function recordRevision(int $storeId, string $entityType, int|string $entityId, string $action = 'upsert', ?array $payload = null): FlutterSyncRevision
    {
        return (new Store)->getConnection()->transaction(function () use ($storeId, $entityType, $entityId, $action, $payload): FlutterSyncRevision {
            Store::query()->whereKey($storeId)->lockForUpdate()->firstOrFail();

            return FlutterSyncRevision::query()->create([
                'store_id' => $storeId, 'entity_type' => $entityType, 'entity_id' => (int) $entityId,
                'action'   => $action, 'revision' => $this->entityRevision($storeId, $entityType, $entityId) + 1,
                'payload'  => $payload, 'created_at' => now(),
            ]);
        });
    }

    public function entityRevision(int $storeId, string $entityType, int|string $entityId): int
    {
        return (int) FlutterSyncRevision::query()->where('store_id', $storeId)->where('entity_type', $entityType)
            ->where('entity_id', $entityId)->max('revision');
    }

    public function getLatestWatermark(int $storeId): int
    {
        return (int) FlutterSyncRevision::query()->where('store_id', $storeId)->max('id');
    }

    public function productsQuery(int $storeId): Builder
    {
        $stockInStore = fn ($query) => $query->whereHas('stock', fn ($stock) => $stock->withoutGlobalScope('current_store')
            ->whereHas('stores', fn ($stores) => $stores->whereKey($storeId)));

        return Product::withoutGlobalScope('current_store')->where(function (Builder $query) use ($storeId): void {
            $query->where('store_id', $storeId)
                ->orWhereHas('productStocks.stock', fn ($stock) => $stock->withoutGlobalScope('current_store')->whereHas('stores', fn ($stores) => $stores->whereKey($storeId)))
                ->orWhereHas('sizes.productStocks.stock', fn ($stock) => $stock->withoutGlobalScope('current_store')->whereHas('stores', fn ($stores) => $stores->whereKey($storeId)))
                ->orWhereHas('sizes.stocks.stock', fn ($stock) => $stock->withoutGlobalScope('current_store')->whereHas('stores', fn ($stores) => $stores->whereKey($storeId)));
        })
            ->addSelect(['sync_revision' => FlutterSyncRevision::query()->selectRaw('MAX(revision)')->whereColumn('entity_id', 'products.id')->where('store_id', $storeId)->where('entity_type', 'product')])
            ->with(['media', 'productStocks' => $stockInStore,
                'sizes' => fn ($sizes) => $sizes->with(['productStocks' => $stockInStore, 'stocks' => $stockInStore])
                    ->withCount(['productStocks as mobile_stock_count' => $stockInStore])
                    ->withSum(['productStocks as mobile_quantity' => $stockInStore], 'quantity')
                    ->withSum(['stocks as legacy_quantity' => $stockInStore], 'quantity')])
            ->withSum(['productStocks as store_quantity' => fn ($query) => $stockInStore($query)->whereNull('product_size_id')], 'quantity');
    }

    public function permissionFingerprint(User $user): string
    {
        $permissions = $user->getAllPermissions()->pluck('name')->sort()->values()->all();

        return hash('sha256', json_encode($permissions, JSON_THROW_ON_ERROR));
    }

    public function createSnapshot(User $user, Store $store, FlutterDevice $device): FlutterSyncSnapshot
    {
        $canReadProducts = Gate::forUser($user)->allows('viewAny', Product::class) || $user->can('View:Pos');
        $canReadDebtors  = Gate::forUser($user)->allows('viewAny', Debtor::class);
        abort_unless($canReadProducts || $canReadDebtors, 403);

        return $store->getConnection()->transaction(function () use ($user, $store, $device, $canReadProducts, $canReadDebtors): FlutterSyncSnapshot {
            if ($store->getConnection()->getDriverName() === 'pgsql' && $store->getConnection()->transactionLevel() === 1) {
                $store->getConnection()->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $catalog        = $canReadProducts ? $this->productsQuery($store->id)->orderBy('id')->sharedLock()->get() : collect();
            $hasCostHistory = FlutterSyncRevision::query()->where('store_id', $store->id)->where('entity_type', 'product')
                ->whereNotNull('payload->unit_cost')->pluck('entity_id')->flip();
            foreach ($catalog as $product) {
                if (!$hasCostHistory->has($product->id)) {
                    $revision = $this->recordRevision($store->id, 'product', $product->id, 'upsert', [
                        'unit_cost' => (float) $product->initial_price, 'catalog_price' => (float) $product->price,
                    ]);
                    $product->setAttribute('sync_revision', $revision->revision);
                }
            }
            $products = $catalog->map(fn ($product): array => (new ProductResource($product))->resolve(request()))->all();
            $debtors  = $canReadDebtors ? Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id)
                ->addSelect(['sync_revision' => FlutterSyncRevision::query()->selectRaw('MAX(revision)')->whereColumn('entity_id', 'debtors.id')->where('store_id', $store->id)->where('entity_type', 'debtor')])
                ->with(['client', 'transactions'])->orderBy('id')->get()
                ->map(fn ($debtor): array => (new DebtorResource($debtor))->resolve(request()))->all() : [];

            $clients = $user->can('View:Pos') ? \App\Models\Client::query()->orderBy('id')->get()
                ->map(fn ($client): array => (new \App\Http\Resources\Flutter\ClientResource($client))->resolve(request()))->all() : [];

            return FlutterSyncSnapshot::query()->create([
                'store_id'        => $store->id, 'user_id' => $user->id, 'device_id' => $device->id,
                'watermark'       => $this->getLatestWatermark($store->id), 'schema_version' => 1,
                'status'          => 'ready', 'expires_at' => now()->addHours(24),
                'data'            => ['products' => $products, 'debtors' => $debtors, 'clients' => $clients],
                'permission_hash' => $this->permissionFingerprint($user),
            ]);
        }, 3);
    }

    public function getManifestCounts(Store $store): array
    {
        return ['products' => Product::withoutGlobalScope('current_store')->where('store_id', $store->id)->count(),
            'debtors'      => Debtor::withoutGlobalScope('current_store')->where('store_id', $store->id)->count()];
    }
}
