<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpOperation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'expires_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'connection_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(McpDelivery::class, 'operation_id');
    }
}
