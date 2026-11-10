<?php

namespace App\Filament\Pages;

use UnitEnum;
use Throwable;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use App\Enums\NavigationGroup;
use Livewire\Attributes\Locked;
use App\Models\PushAnnouncement;
use Livewire\Attributes\Computed;
use App\Jobs\SendPushAnnouncement;
use Illuminate\Support\Facades\Bus;
use App\Services\FirebasePushSender;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Filament\Forms\Concerns\InteractsWithForms;

class PushAnnouncements extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Push xabar yuborish';

    protected static ?string $title = 'Push xabar yuborish';

    protected static ?string $slug = 'settings/push-announcements';

    protected string $view = 'filament.pages.push-announcements';

    /** @var array{title?: string, body?: string} */
    public array $data = [];

    #[Locked]
    public string $requestId;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->requestId = (string) Str::uuid();
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('Sarlavha')
                ->required()
                ->rules(['string', 'regex:/\S/u'])
                ->maxLength(80)
                ->live(debounce: 300),
            Textarea::make('body')
                ->label('Xabar matni')
                ->required()
                ->rules(['string', 'regex:/\S/u'])
                ->maxLength(300)
                ->rows(4)
                ->live(debounce: 300),
        ])->statePath('data');
    }

    #[Computed]
    public function firebaseConfigured(): bool
    {
        return app(FirebasePushSender::class)->isConfigured();
    }

    /** @return Collection<int, PushAnnouncement> */
    #[Computed]
    public function announcements(): Collection
    {
        abort_unless(static::canAccess(), 403);

        return PushAnnouncement::query()->latest('id')->limit(25)->get();
    }

    public function sendAction(): Action
    {
        return Action::make('send')
            ->label('Yuborishni tasdiqlash')
            ->icon('heroicon-o-paper-airplane')
            ->disabled(fn (): bool => !$this->firebaseConfigured)
            ->requiresConfirmation()
            ->modalHeading('Push xabarni yuborish')
            ->modalDescription('Xabar barcha do‘konlardagi umumiy e’lonlarga obuna bo‘lgan Android qurilmalarga yuboriladi.')
            ->modalSubmitActionLabel('Barchaga yuborish')
            ->mountUsing(fn (): array => $this->validatedMessage())
            ->action(fn () => $this->queueAnnouncement());
    }

    /** @return array{title: string, body: string} */
    private function validatedMessage(): array
    {
        try {
            return $this->form->getState();
        } catch (ValidationException $exception) {
            $this->unmountAction(canCancelParentActions: false);

            throw $exception;
        }
    }

    private function queueAnnouncement(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(app(FirebasePushSender::class)->isConfigured(), 503);

        $data = $this->validatedMessage();
        $user = auth()->user();

        try {
            (new PushAnnouncement)->getConnection()->transaction(function () use ($data, $user): void {
                $announcement = PushAnnouncement::query()->firstOrCreate(
                    ['request_id' => $this->requestId],
                    [
                        'sent_by'     => $user->id,
                        'sender_name' => $user->name,
                        'title'       => trim($data['title']),
                        'body'        => trim($data['body']),
                    ],
                );

                if ($announcement->wasRecentlyCreated) {
                    Bus::dispatch((new SendPushAnnouncement($announcement->id))
                        ->onConnection('database')
                        ->beforeCommit());
                }
            });
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title('Xabarni navbatga qo‘yib bo‘lmadi.')->danger()->send();

            return;
        }

        $this->requestId = (string) Str::uuid();
        $this->form->fill();
        unset($this->announcements);

        Notification::make()->title('Xabar navbatga qo‘yildi.')->success()->send();
    }
}
