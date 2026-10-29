<?php

use App\Models\Clinic;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
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
        ->assertSee('Northside Clinic');
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

it('supports clinic search, sorting, and pagination in the clinics table', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    Clinic::factory()->create(['name' => 'Alpha Clinic', 'city' => 'Rabat', 'phone' => null]);
    Clinic::factory()->create(['name' => 'Beta Clinic', 'city' => 'Agadir']);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.index')
        ->assertSee('Alpha Clinic')
        ->assertSee('Beta Clinic')
        ->set('search', 'Rabat')
        ->assertSee('Alpha Clinic')
        ->assertDontSee('Beta Clinic')
        ->set('search', '')
        ->call('sort', 'name')
        ->assertSet('sortBy', 'name');
});

it('supports sorting by clinic table values', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    Clinic::factory()->create(['name' => 'Alpha Clinic']);
    Clinic::factory()->create(['name' => 'Beta Clinic']);

    foreach (['name', 'city', 'phone', 'is_active', 'created_at'] as $sortColumn) {
        Livewire::actingAs($owner)
            ->test('pages::owner.clinics.index')
            ->call('sort', $sortColumn)
            ->assertSet('sortBy', $sortColumn);
    }
});

it('displays clinic users with their clinic roles and effective permissions in the members modal', function () {
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

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.index')
        ->call('openMembersModal', $clinic->id)
        ->assertSet('selectedClinicIdForMembers', $clinic->id)
        ->assertSee('Clinic Member')
        ->assertSee('member@example.test')
        ->assertSee('receptionist')
        ->assertSee('appointments.view');
});

it('only accepts whitelisted sort columns without error', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    Clinic::factory()->create(['name' => 'Alpha Clinic']);
    Clinic::factory()->create(['name' => 'Beta Clinic']);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.index')
        ->call('sort', 'name; DROP TABLE clinics')
        ->assertSet('sortBy', 'name; DROP TABLE clinics')
        ->assertHasNoErrors();

    expect(Clinic::query()->count())->toBe(2);
});

it('does not expose the owner clinics page to clinic staff', function () {
    $clinic = Clinic::factory()->create();
    $staff = User::factory()->create(['is_platform_owner' => false]);
    $staff->clinics()->attach($clinic->id, [
        'role' => 'clinic_staff',
        'is_active' => true,
    ]);

    $this->actingAs($staff)
        ->get(route('owner.clinics.index'))
        ->assertForbidden();
});

it('allows a platform owner to suspend and reactivate a clinic', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create(['is_active' => true]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.index')
        ->call('confirmToggleStatus', $clinic->id)
        ->call('toggleClinicStatus')
        ->assertDispatched('toast-show');

    expect($clinic->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.index')
        ->call('confirmToggleStatus', $clinic->id)
        ->call('toggleClinicStatus')
        ->assertDispatched('toast-show');

    expect($clinic->fresh()->is_active)->toBeTrue();
});

it('displays subscription badge data and created_at in the clinic table', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $plan = Plan::factory()->create(['name' => 'Premium Plan']);
    $clinicWithSub = Clinic::factory()->create(['name' => 'Subscribed Clinic']);
    $clinicNoSub = Clinic::factory()->create(['name' => 'Empty Clinic']);

    Subscription::factory()->create([
        'clinic_id' => $clinicWithSub->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ends_at' => now()->addMonth(),
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.index')
        ->assertSee(__('Active').' - Premium Plan')
        ->assertSee(__('No subscription'));
});

afterEach(function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});
