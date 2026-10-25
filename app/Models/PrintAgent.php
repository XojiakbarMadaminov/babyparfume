<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PrintAgent extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function store(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function printers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PrintPrinter::class, 'agent_id');
    }
}
