<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Schemas\Schema;
use App\Enums\NavigationGroup;
use App\Models\TelegramSetting;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Forms\Concerns\InteractsWithForms;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

class TelegramSettings extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected static string|null|\UnitEnum $navigationGroup  = NavigationGroup::Settings;
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?string $navigationLabel                = 'Telegram sozlamalari';
    protected static ?string $title                          = 'Telegram sozlamalari';
    protected static ?string $slug                           = 'settings/telegram';
    protected static ?int $navigationSort                    = 1;

    protected string $view = 'filament.pages.telegram-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = TelegramSetting::query()->first();

        $this->form->fill([
            'bot_token'     => $settings?->bot_token ?? config('services.telegram.bot_token'),
            'sales_chat_id' => $settings?->sales_chat_id ?? config('services.telegram.sales_chat_id'),
            'debt_chat_id'  => $settings?->sales_chat_id ?? config('services.telegram.debt_chat_id'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Telegram sozlamalari')
                ->schema([
                    TextInput::make('bot_token')
                        ->label('Bot token')
                        ->required()
                        ->columnSpanFull(),

                    TextInput::make('sales_chat_id')
                        ->label('Guruh chat ID')
                        ->required()
                        ->columnSpanFull(),
                ])
                ->columns(1),
        ])->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        $settings = TelegramSetting::query()->firstOrNew([]);
        $settings->fill($data)->save();

        $this->form->fill($data);

        Notification::make()
            ->title('Telegram sozlamalari saqlandi')
            ->success()
            ->send();
    }
}
