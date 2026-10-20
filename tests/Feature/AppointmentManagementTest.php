<?php

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Patient;
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

it('can schedule an appointment for a patient', function () {
    $patient = Patient::factory()->create();
    $service = Service::factory()->create();
    $doctor = User::factory()->create();
    $receptionist = User::factory()->create();

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'provider_id' => $doctor->id,
        'created_by' => $receptionist->id,
        'scheduled_at' => now()->addDay()->setTime(10, 0),
    ]);

    expect($appointment->patient->id)->toBe($patient->id);
    expect($appointment->service->id)->toBe($service->id);
    expect($appointment->provider->id)->toBe($doctor->id);
    expect($appointment->scheduled_at->format('H:i'))->toBe('10:00');
});

it('prevents a doctor from having two appointments at the exact same time', function () {
    $doctor = User::factory()->create();
    $time = now()->addDay()->setTime(10, 0);

    // Patient 1 books at 10:00
    Appointment::factory()->create([
        'provider_id' => $doctor->id,
        'scheduled_at' => $time,
    ]);

    // Patient 2 tries to book at exactly 10:00 (should throw a DB error)
    expect(fn () => Appointment::factory()->create([
        'provider_id' => $doctor->id,
        'scheduled_at' => $time,
    ]))->toThrow(QueryException::class);
});
