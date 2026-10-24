<?php

use App\Models\Clinic;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

it('allows a platform owner to view the clinics list', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    Clinic::factory()->create(['name' => 'Northside Clinic']);

    $this->actingAs($owner)
        ->get(route('owner.clinics.index'))
        ->assertOk()
        ->assertSee('owner-clinics-table');
});

it('allows a platform owner to create a clinic with an administrator and clinic role', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.index')
        ->set('clinicName', 'Harbor Clinic')
        ->set('clinicPhone', '+212600000000')
        ->set('clinicCity', 'Casablanca')
        ->set('adminName', 'Harbor Admin')
        ->set('adminEmail', 'admin@harbor.example')
        ->set('adminPassword', 'HarborAdmin!2026')
        ->call('createClinic')
        ->assertHasNoErrors()
        ->assertSet('clinicName', '')
        ->assertSet('adminName', '')
        ->assertSet('adminEmail', '');

    $clinic = Clinic::query()->where('slug', 'harbor-clinic')->sole();
    $admin = User::query()->where('email', 'admin@harbor.example')->sole();

    expect($clinic->name)->toBe('Harbor Clinic')
        ->and($clinic->phone)->toBe('+212600000000')
        ->and($clinic->city)->toBe('Casablanca')
        ->and($admin->name)->toBe('Harbor Admin')
        ->and($admin->clinics()->whereKey($clinic->id)->exists())->toBeTrue();

    setPermissionsTeamId($clinic->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin->unsetRelation('roles');

    expect($admin->hasRole('clinic_admin'))->toBeTrue()
        ->and($admin->getAllPermissions()->pluck('name')->all())->toContain('appointments.cancel', 'roles.delete')
        ->and(Role::query()
            ->whereNull('clinic_id')
            ->where('name', 'clinic_admin')
            ->where('is_system', true)
            ->exists())->toBeTrue();
});

it('does not create clinic-specific roles when provisioning a clinic', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.index')
        ->set('clinicName', 'Reception Clinic')
        ->set('createAdmin', false)
        ->call('createClinic')
        ->assertHasNoErrors();

    $clinic = Clinic::query()->where('slug', 'reception-clinic')->sole();

    expect(Role::query()->where('clinic_id', $clinic->id)->exists())->toBeFalse()
        ->and(Role::query()->where('name', 'receptionist')->exists())->toBeFalse();
    expect(fn () => Role::query()->whereNull('clinic_id')->where('name', 'clinic_admin')->firstOrFail()->delete())
        ->toThrow(LogicException::class);
});

it('seeds exactly one global clinic admin role before any clinic exists', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $adminRole = Role::query()
        ->whereNull('clinic_id')
        ->where('name', 'clinic_admin')
        ->firstOrFail();

    expect(Clinic::query()->count())->toBe(0)
        ->and(Role::query()->where('name', 'clinic_admin')->count())->toBe(1)
        ->and((bool) $adminRole->getAttribute('is_system'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('roles.delete'))->toBeTrue()
        ->and(Role::query()->where('name', 'receptionist')->exists())->toBeFalse();
});

it('supports server-side clinic search, sorting, and pagination', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    Clinic::factory()->create(['name' => 'Alpha Clinic', 'city' => 'Rabat', 'phone' => null]);
    Clinic::factory()->create(['name' => 'Beta Clinic', 'city' => 'Agadir']);

    $this->actingAs($owner)
        ->getJson(route('owner.clinics.data', [
            'draw' => 3,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Rabat'],
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [['data' => 'name']],
        ]))
        ->assertOk()
        ->assertJsonPath('draw', 3)
        ->assertJsonPath('recordsTotal', 2)
        ->assertJsonPath('recordsFiltered', 1)
        ->assertJsonPath('data.0.name', 'Alpha Clinic')
        ->assertJsonPath('data.0.city', 'Rabat')
        ->assertJsonPath('data.0.phone', '—')
        ->assertJsonPath('data.0.status_url', route('owner.clinics.status', Clinic::query()->where('name', 'Alpha Clinic')->firstOrFail()));
});

it('supports sorting by derived clinic table values', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    Clinic::factory()->create(['name' => 'Alpha Clinic']);
    Clinic::factory()->create(['name' => 'Beta Clinic']);

    foreach (['city', 'phone', 'subscription_status', 'is_active'] as $sortColumn) {
        $this->actingAs($owner)
            ->getJson(route('owner.clinics.data', [
                'order' => [['column' => 0, 'dir' => 'asc']],
                'columns' => [['data' => $sortColumn]],
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
});

it('returns clinic users with their clinic roles and effective permissions', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $member = User::factory()->create(['name' => 'Clinic Member', 'email' => 'member@example.test']);
    $clinic->users()->attach($member->id, ['role' => 'clinic_staff', 'is_active' => true]);

    setPermissionsTeamId($clinic->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permission = Permission::findOrCreate('appointments.view', 'web');
    $role = Role::query()->create([
        'clinic_id' => $clinic->id,
        'name' => 'receptionist',
        'guard_name' => 'web',
    ]);
    $role->givePermissionTo($permission);
    $member->assignRole($role);

    $this->actingAs($owner)
        ->getJson(route('owner.clinics.members', $clinic))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Clinic Member')
        ->assertJsonPath('data.0.roles.0', 'receptionist')
        ->assertJsonPath('data.0.permissions.0', 'appointments.view');
});

it('only accepts whitelisted DataTables sort columns', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    Clinic::factory()->create(['name' => 'Alpha Clinic']);
    Clinic::factory()->create(['name' => 'Beta Clinic']);

    $this->actingAs($owner)
        ->getJson(route('owner.clinics.data', [
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [['data' => 'name; DROP TABLE clinics']],
        ]))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(Clinic::query()->count())->toBe(2);
});

it('does not expose the clinics data endpoint to clinic staff', function () {
    $clinic = Clinic::factory()->create();
    $staff = User::factory()->create(['is_platform_owner' => false]);
    $staff->clinics()->attach($clinic->id, [
        'role' => 'clinic_staff',
        'is_active' => true,
    ]);

    $this->actingAs($staff)
        ->getJson(route('owner.clinics.data'))
        ->assertRedirect(route('billing.suspended'));
});

it('allows a platform owner to suspend and reactivate a clinic', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create(['is_active' => true]);

    $this->actingAs($owner)
        ->patchJson(route('owner.clinics.status', $clinic))
        ->assertOk()
        ->assertJsonPath('is_active', false);

    expect($clinic->fresh()->is_active)->toBeFalse();

    $this->patchJson(route('owner.clinics.status', $clinic))
        ->assertOk()
        ->assertJsonPath('is_active', true);

    expect($clinic->fresh()->is_active)->toBeTrue();
});

afterEach(function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});
