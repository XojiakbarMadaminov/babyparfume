<?php

namespace App\Http\Controllers\Flutter;

use Illuminate\Http\Request;
use App\Models\FlutterDevice;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Services\FlutterDeviceSessionService;

class DeviceController extends Controller
{
    public function pushToken(\App\Http\Requests\Flutter\PushTokenRequest $request): Response
    {
        $device = $request->attributes->get('flutter_device');
        $token  = $request->validated('token');
        $hash   = hash('sha256', $token);
        $device->getConnection()->transaction(function () use ($device, $token, $hash): void {
            FlutterDevice::query()->where('push_token_hash', $hash)->whereKeyNot($device->id)
                ->update(['push_token' => null, 'push_token_hash' => null]);
            $device->forceFill(['push_token' => $token, 'push_token_hash' => $hash])->save();
        });

        return response()->noContent();
    }

    public function destroy(Request $request, string $device, FlutterDeviceSessionService $sessions): Response
    {
        $registeredDevice = FlutterDevice::query()
            ->where('user_id', $request->user()->getKey())
            ->where('device_uuid', $device)
            ->firstOrFail();
        $sessions->revoke($registeredDevice);

        return response()->noContent();
    }
}
