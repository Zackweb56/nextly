<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->optional(0.8)->phoneNumber(), // 80% chance of having a phone
            'cin' => fake()->optional(0.5)->regexify('[A-Z]{2}[0-9]{6}'), // Moroccan CIN format
            'address' => fake()->optional(0.7)->address(),
            'date_of_birth' => fake()->optional(0.9)->dateTimeBetween('-80 years', '-1 year'),
            'notes' => fake()->optional(0.2)->sentence(),
        ];
    }
}
