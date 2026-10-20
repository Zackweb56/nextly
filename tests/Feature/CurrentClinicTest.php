<?php

use App\Models\Clinic;
use App\Models\User;
use App\Support\CurrentClinic;
use Spatie\Permission\Models\Role;

it('automatically sets the first active clinic in the session', function () {
    $clinic = Clinic::factory()->create(['is_active' => true]);
    $user = User::factory()->create();

    // Attach user to clinic (active)
    $user->clinics()->attach($clinic->id, ['role' => 'clinic_admin', 'is_active' => true]);
    $this->actingAs($user);

    $this->get('/dashboard');

    expect(session('current_clinic_id'))->toBe($clinic->id);
    expect(CurrentClinic::id())->toBe($clinic->id);
});

it('rejects a manipulated session pointing to another clinic', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $user = User::factory()->create();

    // User ONLY belongs to Clinic A
    $user->clinics()->attach($clinicA->id, ['role' => 'clinic_admin', 'is_active' => true]);
    $this->actingAs($user);

    // Hacker manually sets session to Clinic B
    session(['current_clinic_id' => $clinicB->id]);

    $this->get('/dashboard');

    // The middleware should reject Clinic B and fallback to Clinic A
    expect(CurrentClinic::id())->toBe($clinicA->id);
});

it('ignores inactive clinics', function () {
    $inactiveClinic = Clinic::factory()->create(['is_active' => false]);
    $user = User::factory()->create();

    $user->clinics()->attach($inactiveClinic->id, ['role' => 'clinic_admin', 'is_active' => true]);
    $this->actingAs($user);

    $this->get('/dashboard');

    expect(CurrentClinic::id())->toBeNull();
});

it('sets the spatie team id based on the current clinic', function () {
    $clinic = Clinic::factory()->create();
    $user = User::factory()->create();
    $user->clinics()->attach($clinic->id, ['role' => 'clinic_admin', 'is_active' => true]);

    $this->actingAs($user);

    // Create role for this clinic
    setPermissionsTeamId($clinic->id);
    Role::create(['name' => 'clinic_admin', 'clinic_id' => $clinic->id]);

    // Reset team id to simulate a fresh request
    setPermissionsTeamId(null);

    $this->get('/dashboard');

    // The middleware should have set the team ID to $clinic->id
    expect(getPermissionsTeamId())->toBe($clinic->id);
});
