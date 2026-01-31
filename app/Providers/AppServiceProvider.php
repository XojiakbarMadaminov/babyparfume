<?php

namespace App\Providers;

use App\Models\Debtor;
use App\Models\Sale;
use App\Models\Product;
use App\Observers\DebtorObserver;
use App\Observers\SaleObserver;
use Illuminate\Support\Facades\Event;
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

        // Remove original product images after the optimized conversion is generated
        Event::listen(ConversionHasBeenCompletedEvent::class, function (ConversionHasBeenCompletedEvent $event) {
            $media = $event->media;

            if ($media->collection_name !== Product::IMAGE_COLLECTION) {
                return;
            }

            if ($event->conversion->getName() !== Product::OPTIMIZED_CONVERSION) {
                return;
            }

            if ($media->getCustomProperty('original_deleted')) {
                return;
            }

            $path = $media->getPath();
            if ($path && file_exists($path)) {
                @unlink($path);
            }

            $media->setCustomProperty('original_deleted', true);
            $media->save();
        });
    }
}
