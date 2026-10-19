<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Schemas\Schema;
use App\Enums\NavigationGroup;
use App\Models\GeneralSetting;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
        $settings     = GeneralSetting::current();
        $isSuperAdmin = auth()->user()?->hasRole('super_admin') ?? false;

        $state = [
            'barcode_show_price' => (bool) $settings->barcode_show_price,
        ];

        if ($isSuperAdmin) {
            $state['subscription_warning_active'] = (bool) $settings->subscription_warning_active;
            $state['subscription_warning_text']   = $settings->subscription_warning_text ?: 'Oylik obuna to‘lovini to‘lashingiz talab qilinadi';
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin') ?? false;

        $sections = [
            Section::make('Umumiy sozlamalar')
                ->schema([
                    Toggle::make('barcode_show_price')
                        ->label('Barcode da narx chiqsinmi?')
                        ->helperText('Yoniq bo‘lsa, tovar barcode chiqarganda narx ham ko‘rsatiladi.')
                        ->inline(false),
                ])
                ->columns(1),
        ];

        if ($isSuperAdmin) {
            $sections[] = Section::make('Obuna to‘lovi ogohlantirishi')
                ->description('Faqat Super Admin uchun: mobil ilovada oylik to‘lov haqida ogohlantiruvchi yuguruvchi satrni yoqish yoki o‘chirish.')
                ->schema([
                    Toggle::make('subscription_warning_active')
                        ->label('Mobil ilovada obuna to‘lovi xabarnomasini yoqish')
                        ->helperText('Yoqilganda, mobil ilova yuqori qismida aylanma satrda ogohlantirish xabari chiqib turadi (ishlashga xalaqit bermaydi).')
                        ->reactive()
                        ->inline(false),

                    TextInput::make('subscription_warning_text')
                        ->label('Xabarnoma matni')
                        ->placeholder('Oylik obuna to‘lovini to‘lashingiz talab qilinadi')
                        ->helperText('Bo‘sh qoldirilsa: "Oylik obuna to‘lovini to‘lashingiz talab qilinadi"')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('subscription_warning_active')),
                ])
                ->columns(1);
        }

        return $schema
            ->components($sections)
            ->statePath('data');
    }

    public function submit(): void
    {
        $data         = $this->form->getState();
        $isSuperAdmin = auth()->user()?->hasRole('super_admin') ?? false;

        $settings = GeneralSetting::current();
        $payload  = [
            'barcode_show_price' => (bool) ($data['barcode_show_price'] ?? false),
        ];

        if ($isSuperAdmin) {
            $payload['subscription_warning_active'] = (bool) ($data['subscription_warning_active'] ?? false);
            if (array_key_exists('subscription_warning_text', $data)) {
                $payload['subscription_warning_text'] = filled($data['subscription_warning_text'])
                    ? trim((string) $data['subscription_warning_text'])
                    : 'Oylik obuna to‘lovini to‘lashingiz talab qilinadi';
            }
        }

        $settings->fill($payload)->save();

        $this->form->fill($this->form->getState());

        Notification::make()
            ->title('Sozlamalar saqlandi')
            ->success()
            ->send();
    }
}
