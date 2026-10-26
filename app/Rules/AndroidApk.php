<?php

namespace App\Rules;

use Closure;
use ZipArchive;
use Illuminate\Http\UploadedFile;
use Illuminate\Contracts\Validation\ValidationRule;

class AndroidApk implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value instanceof UploadedFile || !$value->isValid()) {
            $fail('APK faylini yuklashda xatolik yuz berdi.');

            return;
        }

        $archive = new ZipArchive;
        if ($archive->open($value->getRealPath()) !== true) {
            $fail('Fayl haqiqiy Android APK arxivi bo‘lishi kerak.');

            return;
        }

        try {
            foreach (['AndroidManifest.xml', 'classes.dex'] as $entry) {
                $metadata = $archive->statName($entry);
                if ($metadata === false || $metadata['size'] === 0) {
                    $fail('APK ichida AndroidManifest.xml va classes.dex bo‘lishi kerak.');

                    return;
                }
            }
        } finally {
            $archive->close();
        }
    }
}
