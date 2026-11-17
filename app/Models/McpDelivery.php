<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpDelivery extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'queued'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'finished_at' => 'immutable_datetime'];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(McpOperation::class, 'operation_id');
    }
}
