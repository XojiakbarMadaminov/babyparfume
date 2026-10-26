<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Enums\NavigationGroup;
use App\Models\MobileAppRelease;
use Illuminate\Support\Facades\Storage;

class MobileApp extends Page
{
    protected static string|null|\UnitEnum $navigationGroup = NavigationGroup::Settings;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationLabel = 'Mobil ilova';

    protected static ?string $title = 'Mobil ilova';

    protected static ?string $slug = 'mobile-app';

    protected string $view = 'filament.pages.mobile-app';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    protected function getViewData(): array
    {
        $release = MobileAppRelease::latestRelease();

        return [
            'release'           => $release,
            'downloadAvailable' => $release && Storage::disk('local')->exists($release->file_path),
            'canUpload'         => auth()->user()?->isSuperAdmin() ?? false,
        ];
    }
}
