<?php

namespace App\Filament\Pages;

use App\Models\Store;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Illuminate\Support\Collection;
use App\Models\FlutterSyncConflict;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use App\Services\FlutterOperationService;

class FlutterSyncConflicts extends Page
{
    protected string $view                                   = 'filament.pages.flutter-sync-conflicts';
    protected static ?string $title                          = 'Mobil sinxronlash ziddiyatlari';
    protected static ?string $navigationLabel                = 'Mobil ziddiyatlar';
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-arrow-path';

    public static function canAccess(): bool
    {
        $user  = auth()->user();
        $store = $user?->currentStore;

        return $user && $store && FlutterOperationService::canResolve($user, $store);
    }

    public function conflicts(): Collection
    {
        abort_unless(static::canAccess(), 403);

        return FlutterSyncConflict::query()->where('store_id', auth()->user()->current_store_id)
            ->with(['operation.device', 'user'])->where('status', 'pending')->orderBy('id')->limit(100)->get();
    }

    public function reviewConflictAction(): Action
    {
        return Action::make('resolve')->label('Qaror berish')->requiresConfirmation()
            ->modalDescription('Qabul qilish asl amalni joriy server qoidalari bilan qayta tekshiradi. Telefon takrorlansa mavjud mijozga bog‘lanadi. To‘lov qarzdan oshsa qisman yozilmaydi. Rad etilgan amal auditi saqlanadi.')
            ->schema([
                Select::make('decision')->label('Qaror')->options(['apply' => 'Qabul qilish', 'reject' => 'Rad etish'])->required(),
                Textarea::make('note')->label('Qaror izohi')->required()->maxLength(500),
            ])->action(function (array $arguments, array $data): void {
                abort_unless(static::canAccess(), 403);
                $store = Store::query()->findOrFail(auth()->user()->current_store_id);
                app(FlutterOperationService::class)->resolve(auth()->user(), $store, (int) ($arguments['conflict'] ?? 0), $data['decision'], $data['note']);
                Notification::make()->title('Qaror saqlandi.')->success()->send();
            });
    }
}
