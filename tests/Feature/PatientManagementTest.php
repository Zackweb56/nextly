<?php

use App\Models\Clinic;
use App\Models\Patient;

it('can register a patient for a clinic', function () {
    $clinic = Clinic::factory()->create();

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'Ahmed Alami',
        'phone' => '+212600000000',
    ]);

    expect($patient->clinic->id)->toBe($clinic->id);
    expect($clinic->patients)->toHaveCount(1);
});

it('allows family members to share the same phone number', function () {
    $clinic = Clinic::factory()->create();
    $sharedPhone = '+212611111111';

    $mother = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'Fatima',
        'phone' => $sharedPhone,
    ]);

    $child = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'Youssef',
        'phone' => $sharedPhone,
    ]);

    // Both should be saved successfully
    expect(Patient::count())->toBe(2);
    expect($mother->phone)->toBe($child->phone);
});

it('allows creating a patient without a phone number', function () {
    $clinic = Clinic::factory()->create();

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'Elderly Patient',
        'phone' => null,
    ]);

    expect($patient->phone)->toBeNull();
});
