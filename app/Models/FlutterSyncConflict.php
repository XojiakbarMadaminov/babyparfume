<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlutterSyncConflict extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_RESOLVED = 'resolved';

    public const RESOLUTION_APPLIED  = 'applied';
    public const RESOLUTION_REJECTED = 'rejected';
    public const RESOLUTION_MERGED   = 'merged';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'server_state' => 'array',
            'client_state' => 'array',
            'resolved_at'  => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(FlutterSyncOperation::class, 'operation_id', 'operation_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
