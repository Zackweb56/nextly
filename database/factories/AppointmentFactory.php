<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'patient_id' => Patient::factory(),
            'service_id' => Service::factory(),
            // A random user acting as the doctor
            'provider_id' => User::factory(),
            // Random date within the next 30 days
            'scheduled_at' => fake()->dateTimeBetween('now', '+30 days'),
            'duration_minutes' => 15,
            'status' => 'confirmed',
            'created_by' => User::factory(),
        ];
    }
}
