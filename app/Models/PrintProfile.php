<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PrintProfile extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['layout' => 'array', 'active' => 'boolean', 'selectable' => 'boolean', 'is_default' => 'boolean', 'version' => 'integer', 'default_copies' => 'integer', 'max_copies' => 'integer'];
    }

    public function store(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function printer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PrintPrinter::class, 'printer_id');
    }
}
