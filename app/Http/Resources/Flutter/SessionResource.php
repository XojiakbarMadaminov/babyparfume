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
            'offline_ready'     => false,
        ];
    }
}
