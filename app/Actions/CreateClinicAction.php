<?php

namespace App\Actions;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CreateClinicAction
{
    /**
     * Execute the clinic onboarding process.
     *
     * @param  array{name: string, slug: string, admin_name: string, admin_email: string}  $data
     */
    public function execute(array $data): Clinic
    {
        $previousTeamId = getPermissionsTeamId();

        try {
            return DB::transaction(function () use ($data): Clinic {
                $clinic = Clinic::create([
                    'name' => $data['name'],
                    'slug' => $data['slug'],
                    'is_active' => true,
                ]);

                $admin = User::create([
                    'name' => $data['admin_name'],
                    'email' => $data['admin_email'],
                    'password' => Str::random(40),
                ]);

                setPermissionsTeamId($clinic->id);
                app(PermissionRegistrar::class)->forgetCachedPermissions();

                $adminRole = Role::findOrCreate('clinic_admin', 'web');
                $adminRole->forceFill(['is_system' => true])->save();

                $admin->clinics()->attach($clinic->id, [
                    'role' => 'clinic_admin',
                    'is_active' => true,
                    'joined_at' => now(),
                ]);

                $admin->assignRole($adminRole);

                return $clinic;
            });
        } finally {
            setPermissionsTeamId($previousTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
