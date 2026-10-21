<?php

namespace App\Http\Controllers\Flutter;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\FlutterOperationService;
use App\Http\Requests\Flutter\ResolveConflictRequest;

class ConflictController extends Controller
{
    public function update(ResolveConflictRequest $request, string $storeId, string $conflict, FlutterOperationService $service): JsonResponse
    {
        return response()->json(['data' => $service->resolve(
            $request->user(),
            $request->attributes->get('flutter_store'),
            (int) $conflict,
            $request->validated('decision'),
            $request->validated('note')
        )]);
    }
}
