<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\Printing\PrintAccess;
use Spatie\Permission\Models\Permission;

class PrintingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PrintAccess::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
