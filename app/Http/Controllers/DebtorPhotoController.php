<?php

namespace App\Http\Controllers;

use App\Models\Debtor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DebtorPhotoController extends Controller
{
    public function __invoke(Debtor $debtor): StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user?->current_store_id && $user->stores()->whereKey($debtor->store_id)->exists(), 403);
        Gate::authorize('view', $debtor);
        abort_unless($debtor->photo_path && Storage::disk('local')->exists($debtor->photo_path), 404);

        return Storage::disk('local')->response($debtor->photo_path, null, [
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
