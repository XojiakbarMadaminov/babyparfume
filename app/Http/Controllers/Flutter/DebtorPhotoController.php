<?php

namespace App\Http\Controllers\Flutter;

use App\Models\Debtor;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DebtorPhotoController extends Controller
{
    public function __invoke(Request $request, string $storeId, string $debtorId): StreamedResponse
    {
        $store = $request->attributes->get('flutter_store');

        $debtor = Debtor::withoutGlobalScope('current_store')
            ->where('store_id', $store->id)
            ->findOrFail($debtorId);

        Gate::forUser($request->user())->authorize('view', $debtor);

        abort_unless($debtor->photo_path && Storage::disk('local')->exists($debtor->photo_path), 404);

        return Storage::disk('local')->response($debtor->photo_path, null, [
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
