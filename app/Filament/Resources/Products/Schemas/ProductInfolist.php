<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Schemas\Schema;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                SpatieMediaLibraryImageEntry::make('images')
                    ->label('Mahsulot rasmi')
                    ->collection(Product::IMAGE_COLLECTION)
                    ->conversion(Product::OPTIMIZED_CONVERSION)
                    ->visibility('public')
                    ->visible(fn (Product $record): bool => $record->hasMedia(Product::IMAGE_COLLECTION))
                    ->imageHeight(240)
                    ->columnSpanFull(),
                TextEntry::make('name')->label('Nomi'),
                TextEntry::make('barcode')->label('Bar kod'),
                TextEntry::make('initial_price')->label('Kelgan narxi')->numeric(),
                TextEntry::make('price')->label('Sotish narxi')->numeric(),
            ]);
    }
}
