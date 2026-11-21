<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Filament\Actions\Action;
use App\Filament\Pages\Dashboard;
use Filament\Support\Colors\Color;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Filament\Http\Middleware\AuthenticateSession;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
//            ->profile()
            ->globalSearch(false)
            ->favicon(url('images/oson-pos-logo.png'))
            ->colors([
                'primary' => Color::hex('#484ab5'), // Color::hex('#b5972d') // Color::Amber,
            ])
            ->darkMode()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup(\App\Enums\NavigationGroup::Settings),
            ])
            ->maxContentWidth('screen-2xl')
            ->topNavigation()
            ->renderHook(\Filament\View\PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn (): \Illuminate\Contracts\View\View => view('filament.sms-balance'))
            ->brandName('Oson-POS')
            ->resourceCreatePageRedirect('index')
            ->resourceEditPageRedirect('index')
            ->userMenuItems([
                Action::make('mcpConnections')
                    ->label('AI / MCP ulanishlari')
                    ->icon('heroicon-o-link')
                    ->visible(fn (): bool => (bool) config('mcp.enabled'))
                    ->url(fn (): string => route('mcp.connections.index')),
                Action::make('currentStore')
                    ->label(fn () => auth()->user()?->currentStore?->name ?? 'magazin tanlanmagan')
                    ->color(Color::hex('#484ab5'))
                    ->disabled(),
                Action::make('switchStore')
                    ->label('Magazin tanlash')
                    ->icon('heroicon-o-arrow-path')
                    ->schema([
                        Select::make('store_id')
                            ->label('Store')
                            ->options(fn () => auth()->user()?->stores()->pluck('stores.name', 'stores.id') ?? [])
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $user = auth()->user();
                        if ($user && $user->stores->contains($data['store_id'])) {
                            $user->update(['current_store_id' => $data['store_id']]);
                            cache()->forget('active_stocks_for_store_' . auth()->id());
                            Notification::make()
                                ->title('Store yangilandi')
                                ->body('Hozirgi store: ' . $user->currentStore?->name)
                                ->success()
                                ->send();

                            return redirect(request()->header('Referer'));
                        }
                    }),
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css');
    }
}
