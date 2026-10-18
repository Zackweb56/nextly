<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word().' Plan',
            'slug' => fake()->unique()->slug(),
            'price' => fake()->randomElement([199, 299, 499]),
            'billing_interval' => 'monthly',
            'trial_days' => 14,
            'is_active' => true,
        ];
    }
}
