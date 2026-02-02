<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Traits\HasCurrentStoreScope;

class Debtor extends Model
{
    use HasCurrentStoreScope;

    protected $table   = 'debtors';
    protected $guarded = [];

    public function transactions()
    {
        return $this->hasMany(DebtorTransaction::class)
            ->with('sale')
            ->orderBy('date');
    }

    public function latestTransaction()
    {
        return $this->hasOne(DebtorTransaction::class)->latestOfMany('date');
    }

    public function scopeZeroDebt(Builder $query): Builder
    {
        return $query->where('amount', '<=', 0);
    }

    protected function scopeStillInDebt(Builder $query): Builder
    {
        return $query->where('amount', '>', 0);
    }

    protected function scopeOverdue(Builder $query): Builder
    {
        return $query->where('amount', '>', 0)
            ->where('date', '<', now()->subDays(20)->toDateString());
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
