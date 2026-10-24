<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\ClinicPermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $previousTeamId = getPermissionsTeamId();

        try {
            // We clear the team context to create the global role
            setPermissionsTeamId(null);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            // Create the single global admin role
            $adminRole = Role::findOrCreate('clinic_admin', 'web');

            // Lock it down
            $adminRole->forceFill([
                'clinic_id' => null,
                'is_system' => true,
            ])->save();

            // Give it all clinic permissions
            $adminRole->syncPermissions(ClinicPermissionCatalog::all());

        } finally {
            setPermissionsTeamId($previousTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
