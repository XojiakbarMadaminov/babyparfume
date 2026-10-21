<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FlutterDevice extends Model
{
    /** @use HasFactory<\Database\Factories\FlutterDeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_uuid',
        'name',
        'platform',
        'personal_access_token_id',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return ['revoked_at' => 'immutable_datetime', 'personal_access_token_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
