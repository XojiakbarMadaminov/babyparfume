<?php

namespace App\Http\Resources\Flutter;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $device = $request->attributes->get('flutter_device');

        return [
            'user'   => ['id' => (string) $this->getKey(), 'name' => $this->name],
            'device' => ['uuid' => $device->device_uuid, 'name' => $device->name, 'platform' => $device->platform],
            'stores' => $this->stores()->orderBy('stores.id')->get(['stores.id', 'stores.name'])
                ->map(fn ($store): array => ['id' => (string) $store->id, 'name' => $store->name]),
            'selected_store_id' => $request->attributes->has('flutter_store') ? (string) $request->attributes->get('flutter_store')->getKey() : null,
            'server_time'       => now()->toISOString(),
            'permission_hash'   => app(\App\Services\FlutterSyncService::class)->permissionFingerprint($this->resource),
            'permissions'       => [
                'sales'            => $this->resource->can('View:Pos'),
                'sales_statistics' => $this->resource->can('View:Pos') && $this->resource->can('view_mobile_sales_statistics'),
                'products'         => \Illuminate\Support\Facades\Gate::forUser($this->resource)->allows('viewAny', \App\Models\Product::class),
                'create_product'   => \Illuminate\Support\Facades\Gate::forUser($this->resource)->allows('create', \App\Models\Product::class),
                'debtors'          => \Illuminate\Support\Facades\Gate::forUser($this->resource)->allows('viewAny', \App\Models\Debtor::class),
                'create_debtor'    => \Illuminate\Support\Facades\Gate::forUser($this->resource)->allows('create', \App\Models\Debtor::class),
            ],
            'subscription' => [
                'warning_active' => \App\Models\GeneralSetting::isSubscriptionWarningActive(),
                'warning_text'   => \App\Models\GeneralSetting::getSubscriptionWarningText(),
            ],
            'offline_ready' => false,
        ];
    }
}
