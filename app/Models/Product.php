<?php

namespace App\Models;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\HasCurrentStoreScope;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use HasCurrentStoreScope, HasFactory, InteractsWithMedia, SoftDeletes;

    protected $table   = 'products';
    protected $guarded = [];

    public const TYPE_SIZE            = 'size';
    public const TYPE_PACKAGE         = 'package';
    public const IMAGE_COLLECTION     = 'images';
    public const OPTIMIZED_CONVERSION = 'optimized';
    public const THUMBNAIL_CONVERSION = 'thumbnail';

    protected static bool $missingImageDriverLogged = false;

    protected $casts = [
        'type' => 'string',
    ];

    public function sizes()
    {
        return $this->hasMany(ProductSize::class);
    }

    public function sizeStocks()
    {
        return $this->hasManyThrough(
            ProductSizeStock::class,
            ProductSize::class,
            'product_id',
            'product_size_id',
            'id',
            'id'
        );
    }

    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductStock::class, 'product_id');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'product_id');
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class, 'product_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function getDisplayLabelAttribute(): string
    {
        $parts = [];

        if (filled($this->barcode)) {
            $parts[] = $this->barcode;
        }

        $parts[] = $this->name;

        return implode(' — ', array_filter($parts));
    }

    public function getTotalQuantityAttribute(): int
    {
        // Prefer new unified product_stocks table when available
        $sum = $this->productStocks()->sum('quantity');

        if ($sum > 0) {
            return (int) $sum;
        }

        // Backward compatibility: sum over size-based legacy stocks if any
        return (int) $this->sizeStocks()->sum('quantity');
    }

    public function isSizeBased(): bool
    {
        return ($this->type ?? self::TYPE_SIZE) === self::TYPE_SIZE;
    }

    public function isPackageBased(): bool
    {
        return ($this->type ?? self::TYPE_SIZE) === self::TYPE_PACKAGE;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGE_COLLECTION)
            ->useDisk(config('media-library.disk_name', 'public'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        if (!self::canOptimizeImages()) {
            $this->logMissingImageDriver();

            return;
        }

        foreach ([self::OPTIMIZED_CONVERSION => 960, self::THUMBNAIL_CONVERSION => 160] as $name => $size) {
            $this->addMediaConversion($name)
                ->performOnCollections(self::IMAGE_COLLECTION)
                ->fit(Fit::Max, $size, $size)
                ->format(function_exists('imagewebp') ? 'webp' : 'jpg')
                ->quality(78)
                ->nonOptimized()
                ->nonQueued();
        }
    }

    public static function canOptimizeImages(): bool
    {
        $driver = config('media-library.image_driver', 'gd');

        return in_array($driver, ['gd', 'imagick'], true) && extension_loaded($driver);
    }

    public function getPrimaryImageUrl(): ?string
    {
        $media = $this->getFirstMedia(self::IMAGE_COLLECTION);

        if (!$media) {
            return null;
        }

        if ($media->hasGeneratedConversion(self::OPTIMIZED_CONVERSION)) {
            return $media->getUrl(self::OPTIMIZED_CONVERSION);
        }

        return $media->getUrl();
    }

    public function getThumbnailUrl(): ?string
    {
        $media = $this->getFirstMedia(self::IMAGE_COLLECTION);

        if ($media?->hasGeneratedConversion(self::THUMBNAIL_CONVERSION)) {
            return $media->getUrl(self::THUMBNAIL_CONVERSION);
        }

        return $this->getPrimaryImageUrl();
    }

    public function getImageUrls(): array
    {
        return $this->getMedia(self::IMAGE_COLLECTION)
            ->map(function (Media $media) {
                if ($media->hasGeneratedConversion(self::OPTIMIZED_CONVERSION)) {
                    return $media->getUrl(self::OPTIMIZED_CONVERSION);
                }

                return $media->getUrl();
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function logMissingImageDriver(): void
    {
        if (self::$missingImageDriverLogged) {
            return;
        }

        Log::warning(
            'Skipping product image conversions because the configured image driver is unavailable.'
        );

        self::$missingImageDriverLogged = true;
    }
}
