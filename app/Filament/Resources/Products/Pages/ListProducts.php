<?php

namespace App\Filament\Resources\Products\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Products\ProductResource;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
//            Action::make('gallery')
//                ->label('Gallery')
//                ->icon('heroicon-o-photo')
//                ->color('gray')
//                ->url(ProductResource::getUrl('gallery')),
            CreateAction::make(),
        ];
    }
}
