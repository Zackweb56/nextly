<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'subscription_id' => Subscription::factory(),
            'amount' => 299.00,
            'currency' => 'MAD',
            'status' => 'paid',
            'method' => 'bank_transfer',
            'paid_at' => now(),
        ];
    }
}
