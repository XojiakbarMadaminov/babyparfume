<?php

namespace App\Services;

use RuntimeException;
use App\Models\Debtor;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DebtorPhotoService
{
    public const MAX_WIDTH  = 1920;
    public const MAX_HEIGHT = 1920;
    public const QUALITY    = 85;

    public static function rules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=6000,max_height=6000'];
    }

    public function store(UploadedFile $photo): string
    {
        $originalPath = $photo->getRealPath();
        $contents     = file_get_contents($originalPath);
        $extension    = $photo->extension() ?: 'jpg';

        if (extension_loaded('gd')) {
            $optimized = $this->optimizeWithGd($originalPath, $contents);
            if ($optimized !== null) {
                $contents  = $optimized['contents'];
                $extension = $optimized['extension'];
            }
        }

        $path = 'debtor-photos/' . Str::uuid() . '.' . $extension;
        if (!Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException('Rasmni saqlab bo‘lmadi. Qayta urinib ko‘ring.');
        }

        return $path;
    }

    private function optimizeWithGd(string $filePath, string $rawContents): ?array
    {
        $source = @imagecreatefromstring($rawContents);
        if ($source === false) {
            return null;
        }

        try {
            // EXIF ma'lumotlari orqali mobil kamera burilish burchagini (orientation) to'g'rilash
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($filePath);
                if (!empty($exif['Orientation'])) {
                    $rotated = match ((int) $exif['Orientation']) {
                        3       => @imagerotate($source, 180, 0),
                        6       => @imagerotate($source, -90, 0),
                        8       => @imagerotate($source, 90, 0),
                        default => null,
                    };
                    if ($rotated !== false && $rotated !== null) {
                        imagedestroy($source);
                        $source = $rotated;
                    }
                }
            }

            $width  = imagesx($source);
            $height = imagesy($source);

            // Agar rasm 1920x1920 dan katta bo'lsa, proporsiyasini saqlagan holda moslash (resize)
            if ($width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
                $ratio        = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
                $targetWidth  = max(1, (int) round($width * $ratio));
                $targetHeight = max(1, (int) round($height * $ratio));

                $target = imagecreatetruecolor($targetWidth, $targetHeight);
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

                imagedestroy($source);
                $source = $target;
            }

            $temporary = tempnam(sys_get_temp_dir(), 'debtor-opt-');
            if ($temporary === false) {
                return null;
            }

            try {
                $saved     = false;
                $extension = 'webp';

                if (function_exists('imagewebp')) {
                    imagealphablending($source, false);
                    imagesavealpha($source, true);
                    $saved     = imagewebp($source, $temporary, self::QUALITY);
                    $extension = 'webp';
                }

                if (!$saved && function_exists('imagejpeg')) {
                    $saved     = imagejpeg($source, $temporary, self::QUALITY);
                    $extension = 'jpg';
                }

                if ($saved) {
                    $optimizedContents = file_get_contents($temporary);
                    // Agar hajm kichraygan bo'lsa yoki o'lcham o'zgargan bo'lsa optimallashtirilganini olamiz
                    if (strlen($optimizedContents) < strlen($rawContents) || $width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
                        return [
                            'contents'  => $optimizedContents,
                            'extension' => $extension,
                        ];
                    }
                }
            } finally {
                @unlink($temporary);
            }
        } finally {
            if (is_resource($source) || (is_object($source) && $source instanceof \GdImage)) {
                imagedestroy($source);
            }
        }

        return null;
    }

    public function replace(Debtor $debtor, UploadedFile $photo): void
    {
        $path     = $this->store($photo);
        $previous = $debtor->photo_path;

        try {
            $debtor->update(['photo_path' => $path]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }
    }
}
