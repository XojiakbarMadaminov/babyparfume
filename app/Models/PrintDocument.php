<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PrintDocument extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['profile_snapshot' => 'array', 'payload' => 'array', 'expires_at' => 'immutable_datetime', 'copies' => 'integer', 'total_labels' => 'integer'];
    }

    public function jobs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PrintJob::class, 'document_id');
    }

    public function profile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PrintProfile::class, 'profile_id');
    }
}
