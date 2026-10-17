<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canUpdate   = $request->user()?->can('update', $this->resource) ?? false;
        $canViewCost = $canUpdate || ($request->user()?->can('view_mobile_sales_cost_price') ?? false);

        return [
            'id'            => $this->id,
            'can_update'    => $canUpdate,
            'initial_price' => $this->when($canViewCost, (int) $this->initial_price),
            'name'          => $this->name,
            'barcode'       => $this->barcode,
            'price'         => (int) $this->price,
            'type'          => $this->type,
            'image'         => $this->getPrimaryImageUrl(),
            'quantity'      => (int) $this->store_quantity + (int) $this->sizes->sum(fn ($size): int => (int) ($size->mobile_stock_count ? $size->mobile_quantity : $size->legacy_quantity)),
        ];
    }
}
