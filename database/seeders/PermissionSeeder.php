<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\ClinicPermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (ClinicPermissionCatalog::grouped() as $group => $permissions) {
            foreach ($permissions as $permission) {
                /** @var Permission $perm */
                $perm = Permission::findOrCreate($permission, 'web');
                $perm->group = $group;
                $perm->save();
            }
        }

        Permission::query()
            ->whereNotIn('name', ClinicPermissionCatalog::all())
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
