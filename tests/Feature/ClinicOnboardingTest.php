<?php

use App\Actions\CreateClinicAction;
use App\Models\Clinic;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

it('creates a clinic, an admin user, and assigns the clinic_admin role atomically', function () {
    $clinic = (new CreateClinicAction)->execute([
        'name' => 'Al Amal Clinic',
        'slug' => 'al-amal',
        'admin_name' => 'Dr. Ahmed',
        'admin_email' => 'ahmed@alamal.ma',
    ]);

    expect($clinic)->toBeInstanceOf(Clinic::class);
    expect(Clinic::count())->toBe(1);

    $admin = User::where('email', 'ahmed@alamal.ma')->firstOrFail();
    expect($admin->clinics()->firstOrFail()->id)->toBe($clinic->id);

    $role = Role::whereNull('clinic_id')
        ->where('name', 'clinic_admin')
        ->firstOrFail();
    expect((bool) $role->getAttribute('is_system'))->toBeTrue();
    expect(DB::table('model_has_roles')
        ->where('role_id', $role->id)
        ->where('model_id', $admin->id)
        ->where('model_type', $admin->getMorphClass())
        ->where('clinic_id', $clinic->id)
        ->exists())->toBeTrue();

    setPermissionsTeamId($clinic->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $admin->unsetRelation('roles');
    expect($admin->hasRole($role))->toBeTrue();
});

it('rolls back clinic creation if admin creation fails', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    expect(fn () => (new CreateClinicAction)->execute([
        'name' => 'Failed Clinic',
        'slug' => 'failed-clinic',
        'admin_name' => 'New Admin',
        'admin_email' => 'existing@example.com',
    ]))->toThrow(QueryException::class);

    expect(Clinic::count())->toBe(0);
});

it('detects whether the clinic has an active admin', function () {
    $clinic = (new CreateClinicAction)->execute([
        'name' => 'Test Clinic',
        'slug' => 'test-clinic',
        'admin_name' => 'Admin',
        'admin_email' => 'admin@test.com',
    ]);

    expect($clinic->hasActiveAdmin())->toBeTrue();

    $admin = $clinic->users()->firstOrFail();
    $admin->clinics()->updateExistingPivot($clinic->id, ['is_active' => false]);

    expect($clinic->hasActiveAdmin())->toBeFalse();

    $admin->clinics()->updateExistingPivot($clinic->id, ['is_active' => true]);
    setPermissionsTeamId($clinic->id);
    $admin->removeRole('clinic_admin');

    expect($clinic->hasActiveAdmin())->toBeFalse();
});

afterEach(function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});
