<?php

namespace App\Filament\Forms\Components;

use App\Models\Debtor;
use App\Services\DebtorPhotoService;
use Filament\Forms\Components\Field;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class DebtorPhoto extends Field
{
    protected string $view = 'filament.forms.components.debtor-photo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dehydrated(false)
            ->rules(DebtorPhotoService::rules())
            ->saveRelationshipsUsing(function (Debtor $record, mixed $state): void {
                if ($state instanceof TemporaryUploadedFile) {
                    app(DebtorPhotoService::class)->replace($record, $state);
                }
            });
    }
}
