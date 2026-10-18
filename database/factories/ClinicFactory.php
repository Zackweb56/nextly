<?php

namespace Database\Factories;

use App\Models\Clinic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Clinic>
 */
class ClinicFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(5),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'address' => fake()->address(),
            'city' => fake()->city(),
            'timezone' => 'Africa/Casablanca',
            'opening_hours' => [
                'monday' => ['open' => '09:00', 'close' => '16:00'],
                'tuesday' => ['open' => '09:00', 'close' => '16:00'],
                'wednesday' => ['open' => '09:00', 'close' => '16:00'],
                'thursday' => ['open' => '09:00', 'close' => '16:00'],
                'friday' => ['open' => '09:00', 'close' => '16:00'],
            ],
            'is_active' => true,
        ];
    }
}
