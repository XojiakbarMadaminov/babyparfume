<?php

namespace App\Services\Printing;

use App\Models\User;

class PrintAccess
{
    public const PERMISSIONS = ['manage_printing', 'print_receipts', 'print_barcodes', 'reprint_receipts', 'view_print_jobs', 'test_printing'];

    public static function allows(User $user, int $storeId, string $permission): bool
    {
        return config('printing.enabled') && $user->stores()->whereKey($storeId)->exists()
            && ($user->isSuperAdmin() || $user->can($permission));
    }

    public static function authorize(User $user, int $storeId, string $permission): void
    {
        abort_unless(self::allows($user, $storeId, $permission), 403, 'Chop etish uchun ruxsat berilmagan.');
    }
}
