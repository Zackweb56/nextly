<?php

use App\Models\Clinic;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentClinic;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->user = User::factory()->create();
    $this->user->clinics()->attach($this->clinic->id, [
        'role' => 'clinic_admin',
        'is_active' => true,
    ]);
    $this->actingAs($this->user);
    CurrentClinic::set($this->clinic->id);
});

it('can create a service for a clinic', function () {
    $service = Service::factory()->create([
        'code' => 'A',
    ]);

    expect($service->clinic->id)->toBe($this->clinic->id);
    expect($this->clinic->services)->toHaveCount(1);
});

it('prevents duplicate service codes within the same clinic', function () {
    Service::factory()->create(['code' => 'A']);

    // This should throw a database exception because 'A' already exists for this clinic
    expect(fn () => Service::factory()->create(['code' => 'A']))
        ->toThrow(QueryException::class);
});

it('allows different clinics to use the same service code', function () {
    $clinicB = Clinic::factory()->create();
    $this->user->clinics()->attach($clinicB->id, [
        'role' => 'clinic_admin',
        'is_active' => true,
    ]);

    Service::factory()->create(['code' => 'A']);
    CurrentClinic::set($clinicB->id);
    Service::factory()->create(['code' => 'A']);

    expect(Service::withoutGlobalScope('clinic')->count())->toBe(2);
});
