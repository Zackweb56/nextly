<?php

use App\Models\Clinic;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;

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

it('encrypts patient phone and cin but leaves name searchable', function () {
    $clinic = Clinic::factory()->create();

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'Ahmed Searchable',
        'phone' => '+212600000000',
        'cin' => 'AB123456',
    ]);

    // 1. Verify the model returns the plain text
    expect($patient->name)->toBe('Ahmed Searchable');
    expect($patient->phone)->toBe('+212600000000');
    expect($patient->cin)->toBe('AB123456');

    // 2. Verify the raw database values
    $rawDbRecord = DB::table('patients')->find($patient->id);

    // Name should be plain text (searchable)
    expect($rawDbRecord->name)->toBe('Ahmed Searchable');

    // Phone and CIN should be encrypted
    expect($rawDbRecord->phone)->not->toBe('+212600000000');
    expect($rawDbRecord->cin)->not->toBe('AB123456');
    expect(strlen($rawDbRecord->phone))->toBeGreaterThan(50);
});
