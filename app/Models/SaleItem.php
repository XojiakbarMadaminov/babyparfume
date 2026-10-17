<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\HasCurrentStoreScope;

class SaleItem extends Model
{
    use HasCurrentStoreScope;

    protected $table   = 'sale_items';
    protected $guarded = [];

    protected $casts = [
        'unit_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (SaleItem $item): void {
            if (!array_key_exists('unit_cost', $item->getAttributes())) {
                $item->unit_cost = Product::withoutGlobalScopes()->withTrashed()->whereKey($item->product_id)->value('initial_price');
            }
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }

    public function productSize()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }
}
