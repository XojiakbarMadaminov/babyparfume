<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlutterSyncOperation extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPLIED  = 'applied';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_BLOCKED  = 'blocked';
    public const STATUS_REJECTED = 'rejected';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload'       => 'array',
            'response_data' => 'array',
            'occurred_at'   => 'datetime',
            'applied_at'    => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(FlutterDevice::class, 'device_id');
    }

    public function conflict(): HasOne
    {
        return $this->hasOne(FlutterSyncConflict::class, 'operation_id', 'operation_id');
    }
}
