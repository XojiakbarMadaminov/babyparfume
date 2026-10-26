<?php

namespace App\Services;

use Throwable;
use App\Models\User;
use Illuminate\Support\Str;
use App\Models\MobileAppRelease;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Cache\LockTimeoutException;

class MobileAppReleaseService
{
    /** @param array{version_name: string, version_code: int|string, release_notes?: ?string} $data */
    public function publish(User $user, UploadedFile $file, array $data): MobileAppRelease
    {
        abort_unless($user->isSuperAdmin(), 403);

        $path = $file->storeAs('mobile-apk', Str::uuid() . '.apk', ['disk' => 'local', 'visibility' => 'private']);
        if ($path === false) {
            throw ValidationException::withMessages(['apk' => 'APK saqlanmadi. Avvalgi versiya o‘zgarmadi.']);
        }

        try {
            return Cache::lock('mobile-app-release-publish', 30)->block(5, function () use ($user, $data, $path): MobileAppRelease {
                return (new MobileAppRelease)->getConnection()->transaction(function () use ($user, $data, $path): MobileAppRelease {
                    $latest = MobileAppRelease::latestRelease();
                    if ($latest && (int) $data['version_code'] <= $latest->version_code) {
                        throw ValidationException::withMessages(['version_code' => 'Versiya kodi oxirgi versiyadan (' . $latest->version_code . ') katta bo‘lishi kerak.']);
                    }

                    return MobileAppRelease::query()->create([
                        'version_name'  => $data['version_name'],
                        'version_code'  => $data['version_code'],
                        'file_path'     => $path,
                        'file_size'     => Storage::disk('local')->size($path),
                        'release_notes' => $data['release_notes'] ?? null,
                        'uploaded_by'   => $user->id,
                    ]);
                });
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            if ($exception instanceof LockTimeoutException) {
                throw ValidationException::withMessages(['apk' => 'Boshqa APK yuklanmoqda. Qayta urinib ko‘ring.']);
            }

            throw $exception;
        }
    }
}
