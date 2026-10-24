<?php

use App\Models\Clinic;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

it('isolates roles between clinics using Spatie teams', function () {
    $this->seed(RoleSeeder::class);

    // Clear permission cache to ensure a clean state
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // 1. Setup: Create two clinics
    $clinicA = Clinic::create(['name' => 'Clinic A', 'slug' => 'clinic-a']);
    $clinicB = Clinic::create(['name' => 'Clinic B', 'slug' => 'clinic-b']);

    // 2. Setup: Create a user
    $user = User::create([
        'name' => 'Dr. Ahmed',
        'email' => 'ahmed@example.com',
        'password' => bcrypt('password'),
    ]);

    // 3. Give the user the 'clinic_admin' role in Clinic A ONLY
    setPermissionsTeamId($clinicA->id);

    $role = Role::query()
        ->whereNull('clinic_id')
        ->where('name', 'clinic_admin')
        ->firstOrFail();
    $user->assignRole($role);

    // 4. Assertion 1: The user is an admin when context is Clinic A
    setPermissionsTeamId($clinicA->id);
    $user->unsetRelation('roles'); // Refresh loaded roles relationship
    expect($user->hasRole('clinic_admin'))->toBeTrue();

    // 5. Assertion 2: The user is NOT an admin when context is Clinic B
    setPermissionsTeamId($clinicB->id);
    $user->unsetRelation('roles'); // Refresh loaded roles relationship
    expect($user->hasRole('clinic_admin'))->toBeFalse();
});
