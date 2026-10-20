<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Consultation', 'Blood Draw', 'ECG', 'Ultrasound']),
            'code' => fake()->unique()->randomLetter(), // Generates A, B, C, etc.
            'description' => fake()->sentence(),
            'duration_minutes' => fake()->randomElement([15, 20, 30, 45]),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 10),
        ];
    }
}
