<?php

declare(strict_types=1);

use App\Models\Clinic;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->clinic = Clinic::factory()->create(['is_active' => true]);
    $this->plan = Plan::factory()->create();
    Subscription::factory()->create([
        'clinic_id' => $this->clinic->id,
        'plan_id' => $this->plan->id,
        'status' => 'active',
    ]);

    $this->admin = User::factory()->create([
        'name' => 'Admin User',
        'email' => 'admin@testclinic.com',
        'email_verified_at' => now(),
    ]);
    $this->admin->clinics()->attach($this->clinic->id, [
        'role' => 'clinic_admin',
        'is_active' => true,
        'joined_at' => now(),
    ]);

    setPermissionsTeamId($this->clinic->id);
    $this->admin->assignRole('clinic_admin');
});

it('displays clinic roles including clinic_admin but excluding platform_owner', function (): void {
    Role::create([
        'name' => 'platform_owner',
        'guard_name' => 'web',
        'is_system' => true,
    ]);

    $customRole = Role::create([
        'name' => 'receptionist',
        'guard_name' => 'web',
        'clinic_id' => $this->clinic->id,
        'is_system' => false,
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::clinic.roles.index', ['clinic' => $this->clinic])
        ->assertSee('clinic_admin')
        ->assertSee('receptionist')
        ->assertDontSee('platform_owner');
});

it('allows creating a role with permissions', function (): void {
    Livewire::actingAs($this->admin)
        ->test('pages::clinic.roles.index', ['clinic' => $this->clinic])
        ->set('roleName', 'secretary')
        ->set('selectedPermissions', ['create_user', 'view_users'])
        ->call('saveRole')
        ->assertHasNoErrors();

    $createdRole = Role::where('name', 'secretary')->where('clinic_id', $this->clinic->id)->first();
    expect($createdRole)->not->toBeNull();
    expect($createdRole->is_system)->toBeFalse();
    expect($createdRole->hasPermissionTo('create_user'))->toBeTrue();
    expect($createdRole->hasPermissionTo('view_users'))->toBeTrue();
    expect($createdRole->hasPermissionTo('delete_user'))->toBeFalse();
});

it('toggles all permissions in a group when group checkbox is clicked', function (): void {
    $component = Livewire::actingAs($this->admin)
        ->test('pages::clinic.roles.index', ['clinic' => $this->clinic]);

    // Initially empty
    expect($component->get('selectedPermissions'))->toBeEmpty();

    // Toggle group on
    $component->call('toggleGroup', 'users');
    $selected = $component->get('selectedPermissions');
    expect($selected)->toContain('create_user');
    expect($selected)->toContain('update_user');
    expect($selected)->toContain('delete_user');

    // Toggle group off
    $component->call('toggleGroup', 'users');
    expect($component->get('selectedPermissions'))->toBeEmpty();
});

it('prevents deleting a role that has assigned users', function (): void {
    $role = Role::create([
        'name' => 'doctor',
        'guard_name' => 'web',
        'clinic_id' => $this->clinic->id,
        'is_system' => false,
    ]);

    $staff = User::factory()->create();
    $staff->clinics()->attach($this->clinic->id, ['role' => 'doctor', 'is_active' => true]);

    setPermissionsTeamId($this->clinic->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $staff->assignRole($role);

    Livewire::actingAs($this->admin)
        ->test('pages::clinic.roles.index', ['clinic' => $this->clinic])
        ->call('deleteRole', $role->id)
        ->assertHasErrors(['role' => __('Ce rôle est attribué à des utilisateurs et ne peut pas être supprimé.')]);

    expect(Role::find($role->id))->not->toBeNull();
});

it('prevents editing or deleting the clinic_admin system role', function (): void {
    $adminRole = Role::whereNull('clinic_id')->where('name', 'clinic_admin')->firstOrFail();

    Livewire::actingAs($this->admin)
        ->test('pages::clinic.roles.index', ['clinic' => $this->clinic])
        ->call('openEditModal', $adminRole->id)
        ->assertHasErrors(['role']);

    Livewire::actingAs($this->admin)
        ->test('pages::clinic.roles.index', ['clinic' => $this->clinic])
        ->call('deleteRole', $adminRole->id)
        ->assertHasErrors(['role']);

    expect(Role::find($adminRole->id))->not->toBeNull();
});

it('allows deleting a custom role without assigned users', function (): void {
    $role = Role::create([
        'name' => 'accountant',
        'guard_name' => 'web',
        'clinic_id' => $this->clinic->id,
        'is_system' => false,
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::clinic.roles.index', ['clinic' => $this->clinic])
        ->call('deleteRole', $role->id)
        ->assertHasNoErrors();

    expect(Role::find($role->id))->toBeNull();
});
