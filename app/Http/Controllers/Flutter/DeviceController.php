<?php

namespace App\Http\Controllers\Flutter;

use Illuminate\Http\Request;
use App\Models\FlutterDevice;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Services\FlutterDeviceSessionService;

class DeviceController extends Controller
{
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
