<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpConnection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['store_ids' => 'array', 'permissions' => 'array', 'default_store_id' => 'integer', 'revoked_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(McpOperation::class, 'connection_id');
    }
}
