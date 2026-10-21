<?php

namespace App\Http\Controllers\Flutter;

use App\Http\Controllers\Controller;

class ClientController extends Controller
{
    public function index(\App\Http\Requests\Flutter\SearchRequest $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        \Illuminate\Support\Facades\Gate::forUser($request->user())->authorize('View:Pos');
        $query = \App\Models\Client::query();
        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn ($clients) => $clients->whereLike('full_name', '%' . $search . '%')->orWhereLike('phone', '%' . $search . '%'));
        }

        return \App\Http\Resources\Flutter\ClientResource::collection($query->orderBy('id')->paginate((int) $request->input('limit', 20)));
    }
}
