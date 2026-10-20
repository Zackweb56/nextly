<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentClinic;

beforeEach(function () {
    $this->clinicA = Clinic::factory()->create(['slug' => 'clinic-a']);
    $this->clinicB = Clinic::factory()->create(['slug' => 'clinic-b']);

    $this->user = User::factory()->create();
    $this->user->clinics()->attach([
        $this->clinicA->id => ['role' => 'clinic_admin', 'is_active' => true],
        $this->clinicB->id => ['role' => 'clinic_admin', 'is_active' => true],
    ]);
});

it('only returns patients belonging to the current clinic', function () {
    // Create patients for both clinics
    // Note: We bypass the scope here to create Clinic B's patient while acting as Clinic A
    $patientA = Patient::withoutGlobalScope('clinic')->create([
        'clinic_id' => $this->clinicA->id,
        'name' => 'Patient A',
    ]);

    $patientB = Patient::withoutGlobalScope('clinic')->create([
        'clinic_id' => $this->clinicB->id,
        'name' => 'Patient B',
    ]);

    // Set context to Clinic A
    CurrentClinic::set($this->clinicA->id);
    $this->actingAs($this->user);

    // Try to fetch all patients
    $patients = Patient::all();

    // Assert we ONLY see Clinic A's patient
    expect($patients)->toHaveCount(1);
    expect($patients->first()->name)->toBe('Patient A');
});

it('automatically assigns clinic_id when creating a record', function () {
    CurrentClinic::set($this->clinicA->id);
    $this->actingAs($this->user);

    // Notice we don't pass clinic_id here!
    $patient = Patient::create([
        'name' => 'New Patient',
    ]);

    expect($patient->clinic_id)->toBe($this->clinicA->id);
});

it('fails safely and returns zero records if no clinic is active', function () {
    Patient::withoutGlobalScope('clinic')->create([
        'clinic_id' => $this->clinicA->id,
        'name' => 'Patient A',
    ]);

    // No clinic set in session (e.g., a Platform Owner)
    $this->actingAs(User::factory()->create());
    session()->forget('current_clinic_id');

    $patients = Patient::all();

    // Should return 0 because of the fail-safe
    expect($patients)->toHaveCount(0);
});
