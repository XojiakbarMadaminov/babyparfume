<?php

namespace App\Http\Resources\Flutter;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user        = $request->user();
        $canUpdate   = $user ? Gate::forUser($user)->allows('update', $this->resource) : false;
        $canDelete   = $user ? Gate::forUser($user)->allows('delete', $this->resource) : false;
        $canViewCost = $canUpdate || ($user?->can('view_mobile_sales_cost_price') ?? false);

        $totalQuantity = (int) $this->store_quantity + (int) $this->sizes->sum(
            fn ($size): int => (int) ($size->mobile_stock_count ? $size->mobile_quantity : $size->legacy_quantity)
        );

        return [
            'id'            => (string) $this->id,
            'name'          => (string) $this->name,
            'barcode'       => (string) $this->barcode,
            'type'          => (string) $this->type,
            'price'         => (string) (int) $this->price,
            'initial_price' => $this->when($canViewCost, (string) (int) $this->initial_price),
            'currency'      => 'UZS',
            'scale'         => 0,
            'quantity'      => $totalQuantity,
            'image'         => $this->getPrimaryImageUrl(),
            'thumbnail'     => $this->getThumbnailUrl(),
            'can_update'    => $canUpdate,
            'can_delete'    => $canDelete,
        ];
    }
}
