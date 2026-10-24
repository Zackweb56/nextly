<?php

namespace Database\Seeders;

use App\Support\ClinicPermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (ClinicPermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }
}
