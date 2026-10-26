<?php

namespace App\Support;

class PhoneNumber
{
    public const PATTERN = '/^\+?[0-9]{7,15}$/';

    public static function forStorage(string $phone): string
    {
        return preg_replace('/[\s()\-]/', '', trim($phone));
    }

    /** @return list<string> */
    public static function aliases(string $phone): array
    {
        $phone   = self::forStorage($phone);
        $digits  = ltrim($phone, '+');
        $aliases = [$phone, $digits, '+' . $digits];

        if ((strlen($digits) === 9 && !str_starts_with($phone, '+'))
            || (strlen($digits) === 12 && str_starts_with($digits, '998'))) {
            $local   = substr($digits, -9);
            $aliases = [...$aliases, $local, '998' . $local, '+998' . $local];
        }

        return array_values(array_unique($aliases));
    }
}
