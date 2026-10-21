<?php

namespace App\Services;

use App\Models\User;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use App\Models\Product;
use Illuminate\Support\Str;
use App\Models\FlutterDevice;
use App\Models\FlutterSyncSnapshot;
use App\Models\FlutterSyncConflict;
use App\Models\FlutterSyncRevision;
use App\Models\FlutterSyncOperation;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\Flutter\DebtorResource;
use App\Http\Resources\Flutter\ProductResource;

class FlutterSyncService
{
    public function recordRevision(int $storeId, string $entityType, int|string $entityId, string $action = 'upsert', ?array $payload = null): FlutterSyncRevision
    {
        $lastRevision = FlutterSyncRevision::query()
            ->where('store_id', $storeId)
            ->where('entity_type', $entityType)
            ->where('entity_id', (int) $entityId)
            ->max('revision') ?? 0;

        return FlutterSyncRevision::query()->create([
            'store_id'    => $storeId,
            'entity_type' => $entityType,
            'entity_id'   => (int) $entityId,
            'action'      => $action,
            'revision'    => $lastRevision + 1,
            'payload'     => $payload,
            'created_at'  => now(),
        ]);
    }

    public function getLatestWatermark(int $storeId): int
    {
        return (int) (FlutterSyncRevision::query()->where('store_id', $storeId)->max('id') ?? 0);
    }

    public function createSnapshot(User $user, Store $store, FlutterDevice $device): FlutterSyncSnapshot
    {
        $watermark = $this->getLatestWatermark($store->id);

        return FlutterSyncSnapshot::query()->create([
            'store_id'       => $store->id,
            'user_id'        => $user->id,
            'device_id'      => $device->id,
            'watermark'      => $watermark,
            'schema_version' => 1,
            'status'         => 'ready',
            'expires_at'     => now()->addHours(24),
        ]);
    }

    /**
     * @param array<string, mixed> $manifestCounts
     * @return array<string, int>
     */
    public function getManifestCounts(Store $store): array
    {
        $productCount = Product::withoutGlobalScope('current_store')
            ->where('store_id', $store->id)
            ->count();

        $debtorCount = Debtor::withoutGlobalScope('current_store')
            ->where('store_id', $store->id)
            ->count();

        return [
            'products' => $productCount,
            'debtors'  => $debtorCount,
        ];
    }
}
