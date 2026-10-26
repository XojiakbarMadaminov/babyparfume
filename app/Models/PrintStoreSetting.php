<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PrintStoreSetting extends Model
{
    use HasFactory;

    protected $guarded    = [];
    protected $primaryKey = 'store_id';
    public $incrementing  = false;

    protected function casts(): array
    {
        return ['auto_print_receipt' => 'boolean'];
    }

    public function store(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
