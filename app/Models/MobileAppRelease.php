<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MobileAppRelease extends Model
{
    /** @use HasFactory<\Database\Factories\MobileAppReleaseFactory> */
    use HasFactory;

    protected $fillable = ['version_name', 'version_code', 'file_path', 'file_size', 'release_notes', 'uploaded_by'];

    protected function casts(): array
    {
        return ['version_code' => 'integer', 'file_size' => 'integer'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public static function latestRelease(): ?self
    {
        return static::query()->orderByDesc('version_code')->first();
    }
}
