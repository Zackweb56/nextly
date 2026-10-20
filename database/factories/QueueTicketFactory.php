<?php

namespace Database\Factories;

use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueueTicket>
 */
class QueueTicketFactory extends Factory
{
    public function definition(): array
    {
        $number = fake()->numberBetween(1, 50);
        $code = fake()->randomLetter();

        return [
            'service_id' => Service::factory(),
            'patient_id' => null,
            'appointment_id' => null,
            'queue_date' => now()->toDateString(),
            'ticket_number' => $number,
            'ticket_code' => $code.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'status' => 'waiting',
            'created_by' => User::factory(),
        ];
    }
}
