<?php

namespace App\Http\Controllers\Flutter;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flutter\LoginRequest;
use App\Services\FlutterDeviceSessionService;
use App\Http\Resources\Flutter\SessionResource;

class SessionController extends Controller
{
    public function store(LoginRequest $request, FlutterDeviceSessionService $sessions): JsonResponse
    {
        return response()->json(['data' => $sessions->login($request->validated())], 201);
    }

    public function show(Request $request): SessionResource
    {
        return new SessionResource($request->user());
    }

    public function destroy(Request $request, FlutterDeviceSessionService $sessions): Response
    {
        $sessions->revoke($request->attributes->get('flutter_device'), $request->user()->currentAccessToken()->getKey());

        return response()->noContent();
    }
}
