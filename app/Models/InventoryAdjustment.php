<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustment extends Model
{
    public const TYPE_RETURN       = 'return';
    public const TYPE_EXCHANGE_IN  = 'exchange_in';
    public const TYPE_EXCHANGE_OUT = 'exchange_out';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
        'unit_price' => 'int',
        'unit_cost'  => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (InventoryAdjustment $adjustment): void {
            if (!array_key_exists('unit_cost', $adjustment->getAttributes())) {
                $adjustment->unit_cost = Product::withoutGlobalScopes()->withTrashed()->whereKey($adjustment->product_id)->value('initial_price') ?? 0;
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function productSize(): BelongsTo
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }
}
