<?php

namespace App\Filament\Forms\Components;

use App\Models\Product;
use Filament\Forms\Components\Field;
use App\Services\ProductPhotoService;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ProductPhoto extends Field
{
    protected string $view = 'filament.forms.components.product-photo';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dehydrated(false)
            ->rules(ProductPhotoService::rules())
            ->validationMessages([
                'image'      => 'JPG, PNG yoki WebP rasm yuklang.',
                'mimes'      => 'Faqat JPG, PNG yoki WebP rasm yuklang.',
                'max'        => 'Rasm 1 MB dan oshmasin.',
                'dimensions' => 'Rasm o‘lchami 2048 × 2048 dan oshmasin.',
            ])
            ->saveRelationshipsUsing(function (Product $record, mixed $state, Get $get): void {
                if ($state instanceof TemporaryUploadedFile) {
                    app(ProductPhotoService::class)->replace($record, $state);
                } elseif ($get('remove_image')) {
                    $record->clearMediaCollection(Product::IMAGE_COLLECTION);
                }
            });
    }
}
