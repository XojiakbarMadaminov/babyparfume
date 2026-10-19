<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneralSetting extends Model
{
    protected $table = 'general_settings';

    protected $guarded = [];

    protected $casts = [
        'barcode_show_price'          => 'boolean',
        'subscription_warning_active' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'barcode_show_price'          => false,
            'subscription_warning_active' => false,
            'subscription_warning_text'   => 'Oylik obuna to‘lovini to‘lashingiz talab qilinadi',
        ]);
    }

    public static function isSubscriptionWarningActive(): bool
    {
        return (bool) (static::query()->value('subscription_warning_active') ?? false);
    }

    public static function getSubscriptionWarningText(): string
    {
        $text = static::query()->value('subscription_warning_text');

        return filled($text) ? (string) $text : 'Oylik obuna to‘lovini to‘lashingiz talab qilinadi';
    }
}
