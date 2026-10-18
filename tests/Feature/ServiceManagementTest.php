<?php

use App\Models\Clinic;
use App\Models\Service;
use Illuminate\Database\QueryException;

it('can create a service for a clinic', function () {
    $clinic = Clinic::factory()->create();

    $service = Service::factory()->create([
        'clinic_id' => $clinic->id,
        'code' => 'A',
    ]);

    expect($service->clinic->id)->toBe($clinic->id);
    expect($clinic->services)->toHaveCount(1);
});

it('prevents duplicate service codes within the same clinic', function () {
    $clinic = Clinic::factory()->create();

    Service::factory()->create(['clinic_id' => $clinic->id, 'code' => 'A']);

    // This should throw a database exception because 'A' already exists for this clinic
    expect(fn () => Service::factory()->create(['clinic_id' => $clinic->id, 'code' => 'A']))
        ->toThrow(QueryException::class);
});

it('allows different clinics to use the same service code', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    Service::factory()->create(['clinic_id' => $clinicA->id, 'code' => 'A']);
    Service::factory()->create(['clinic_id' => $clinicB->id, 'code' => 'A']);

    // If we get here without an exception, the test passes
    expect(Service::count())->toBe(2);
});
