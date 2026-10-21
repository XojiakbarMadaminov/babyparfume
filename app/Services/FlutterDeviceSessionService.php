<?php

namespace App\Services;

use App\Models\User;
use App\Models\FlutterDevice;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class FlutterDeviceSessionService
{
    /**
     * @param  array{email: string, password: string, device_uuid: string, device_name: string, platform: string}  $credentials
     * @return array{token: string, token_type: string, expires_at: string, device_uuid: string, offline_ready: bool}
     */
    public function login(array $credentials): array
    {
        $user = User::query()->where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Email yoki parol noto‘g‘ri.']);
        }

        return $user->getConnection()->transaction(function () use ($user, $credentials): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $device     = FlutterDevice::query()->firstOrNew([
                'user_id'     => $lockedUser->getKey(),
                'device_uuid' => strtolower($credentials['device_uuid']),
            ]);

            $device->token()->delete();
            $expiresAt = now()->addMinutes((int) config('flutter.token_lifetime_minutes'));
            $token     = $lockedUser->createToken('flutter:' . $credentials['device_name'], ['flutter:access'], $expiresAt);

            $device->fill([
                'name'                     => $credentials['device_name'],
                'platform'                 => $credentials['platform'],
                'personal_access_token_id' => $token->accessToken->getKey(),
                'revoked_at'               => null,
            ])->save();

            return [
                'token'         => $token->plainTextToken,
                'token_type'    => 'Bearer',
                'expires_at'    => $expiresAt->toISOString(),
                'device_uuid'   => $device->device_uuid,
                'offline_ready' => false,
            ];
        });
    }

    public function revoke(FlutterDevice $device, ?int $expectedTokenId = null): void
    {
        $device->getConnection()->transaction(function () use ($device, $expectedTokenId): void {
            $lockedDevice = FlutterDevice::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();

            if ($expectedTokenId !== null && $lockedDevice->personal_access_token_id !== $expectedTokenId) {
                return;
            }

            $lockedDevice->token()->delete();
            $lockedDevice->forceFill(['personal_access_token_id' => null, 'revoked_at' => now()])->save();
        });
    }
}
