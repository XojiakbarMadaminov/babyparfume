<?php

namespace App\Http\Controllers;

use App\Models\MobileAppRelease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use App\Services\MobileAppReleaseService;
use App\Http\Requests\StoreMobileAppReleaseRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MobileAppReleaseController extends Controller
{
    public function store(StoreMobileAppReleaseRequest $request, MobileAppReleaseService $service): RedirectResponse
    {
        $service->publish($request->user(), $request->file('apk'), $request->validated());

        return redirect()->route('filament.admin.pages.mobile-app')->with('status', 'Yangi APK muvaffaqiyatli yuklandi.');
    }

    public function download(): StreamedResponse
    {
        $release = MobileAppRelease::latestRelease();
        abort_unless($release && Storage::disk('local')->exists($release->file_path), 404);

        return Storage::disk('local')->download($release->file_path, 'oson-pos-' . $release->version_name . '.apk', [
            'Content-Type'           => 'application/vnd.android.package-archive',
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
