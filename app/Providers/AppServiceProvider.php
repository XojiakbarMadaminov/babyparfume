<?php

namespace App\Providers;

use App\Models\Sale;
use App\Models\Debtor;
use App\Models\Product;
use App\Observers\SaleObserver;
use App\Observers\DebtorObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sale::observe(SaleObserver::class);
        Debtor::observe(DebtorObserver::class);

        Event::listen(ConversionHasBeenCompletedEvent::class, function (ConversionHasBeenCompletedEvent $event): void {
            $media = $event->media;

            if ($media->collection_name !== Product::IMAGE_COLLECTION) {
                return;
            }

            if ($event->conversion->getName() !== Product::THUMBNAIL_CONVERSION || !$media->hasGeneratedConversion(Product::OPTIMIZED_CONVERSION)) {
                return;
            }

            if ($media->getCustomProperty('original_deleted')) {
                return;
            }

            if (!Storage::disk($media->disk)->delete($media->getPathRelativeToRoot())) {
                return;
            }

            $media->setCustomProperty('original_deleted', true);
            $media->save();
        });
    }
}
