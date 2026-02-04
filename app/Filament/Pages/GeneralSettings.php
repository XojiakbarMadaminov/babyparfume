<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Schemas\Schema;
use App\Enums\NavigationGroup;
use App\Models\GeneralSetting;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Forms\Concerns\InteractsWithForms;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

class GeneralSettings extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected static string|null|\UnitEnum $navigationGroup  = NavigationGroup::Settings;
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel                = 'Umumiy sozlamalar';
    protected static ?string $title                          = 'Umumiy sozlamalar';
    protected static ?string $slug                           = 'settings/general';
    protected static ?int $navigationSort                    = 0;

    protected string $view = 'filament.pages.general-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = GeneralSetting::query()->first();

        $this->form->fill([
            'barcode_show_price' => (bool) ($settings?->barcode_show_price ?? false),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Umumiy sozlamalar')
                    ->schema([
                        Toggle::make('barcode_show_price')
                            ->label('Barcode da narx chiqsinmi?')
                            ->helperText('Yoniq bo\'lsa, tovar barcode chiqarganda narx ham ko\'rsatiladi.')
                            ->inline(false),
                    ])
                    ->columns(1),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        $settings = GeneralSetting::query()->firstOrNew([]);
        $settings->fill([
            'barcode_show_price' => (bool) ($data['barcode_show_price'] ?? false),
        ])->save();

        $this->form->fill($data);

        Notification::make()
            ->title('Umumiy sozlamalar saqlandi')
            ->success()
            ->send();
    }
}
