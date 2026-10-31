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

it('allows clinic admin to see clinic users but not themselves', function (): void {
    $staff1 = User::factory()->create([
        'name' => 'Staff Member One',
        'email' => 'staff1@testclinic.com',
    ]);
    $staff1->clinics()->attach($this->clinic->id, [
        'role' => 'clinic_staff',
        'is_active' => true,
        'joined_at' => now(),
    ]);

    $staff2 = User::factory()->create([
        'name' => 'Staff Member Two',
        'email' => 'staff2@testclinic.com',
    ]);
    $staff2->clinics()->attach($this->clinic->id, [
        'role' => 'clinic_staff',
        'is_active' => true,
        'joined_at' => now(),
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::clinic.users.index', ['clinic' => $this->clinic])
        ->assertSee('Staff Member One')
        ->assertSee('staff1@testclinic.com')
        ->assertSee('Staff Member Two')
        ->assertSee('staff2@testclinic.com')
        ->assertDontSee('admin@testclinic.com');
});

it('assigns the correct spatie team role when creating a user', function (): void {
    $customRole = Role::create([
        'name' => 'nurse',
        'guard_name' => 'web',
        'clinic_id' => $this->clinic->id,
        'is_system' => false,
    ]);

    $otherClinic = Clinic::factory()->create(['is_active' => true]);

    Livewire::actingAs($this->admin)
        ->test('pages::clinic.users.index', ['clinic' => $this->clinic])
        ->set('name', 'Noura Mansouri')
        ->set('email', 'noura@testclinic.com')
        ->set('password', 'SecurePass123!')
        ->set('role', 'nurse')
        ->call('createUser')
        ->assertHasNoErrors();

    $newUser = User::where('email', 'noura@testclinic.com')->first();
    expect($newUser)->not->toBeNull();
    expect($newUser->name)->toBe('Noura Mansouri');

    // Attached to clinic with is_active = true
    $membership = $newUser->clinics()->where('clinics.id', $this->clinic->id)->first();
    expect($membership)->not->toBeNull();
    expect($membership->pivot->is_active)->toBe(1);

    // Spatie role scoped to this clinic
    setPermissionsTeamId($this->clinic->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $newUser->unsetRelation('roles');
    expect($newUser->hasRole('nurse'))->toBeTrue();

    // Spatie role NOT present in other clinic
    setPermissionsTeamId($otherClinic->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $newUser->unsetRelation('roles');
    expect($newUser->hasRole('nurse'))->toBeFalse();
});

it('prevents an admin from deleting themselves', function (): void {
    Livewire::actingAs($this->admin)
        ->test('pages::clinic.users.index', ['clinic' => $this->clinic])
        ->call('deleteUser', $this->admin->id)
        ->assertHasErrors(['user']);

    expect(User::find($this->admin->id))->not->toBeNull();
});

it('prevents an admin from deleting another admin', function (): void {
    $anotherAdmin = User::factory()->create([
        'name' => 'Second Admin',
        'email' => 'admin2@testclinic.com',
    ]);
    $anotherAdmin->clinics()->attach($this->clinic->id, [
        'role' => 'clinic_admin',
        'is_active' => true,
    ]);

    setPermissionsTeamId($this->clinic->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $anotherAdmin->assignRole('clinic_admin');

    Livewire::actingAs($this->admin)
        ->test('pages::clinic.users.index', ['clinic' => $this->clinic])
        ->call('deleteUser', $anotherAdmin->id)
        ->assertHasErrors(['user']);

    expect(User::find($anotherAdmin->id))->not->toBeNull();
});

it('allows an admin to delete a staff user', function (): void {
    $staff = User::factory()->create([
        'name' => 'To Delete Staff',
        'email' => 'todelete@testclinic.com',
    ]);
    $staff->clinics()->attach($this->clinic->id, [
        'role' => 'clinic_staff',
        'is_active' => true,
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::clinic.users.index', ['clinic' => $this->clinic])
        ->call('deleteUser', $staff->id)
        ->assertHasNoErrors();

    expect($this->clinic->users()->where('users.id', $staff->id)->exists())->toBeFalse();
});
