<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\DisplayToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DisplayToken>
 */
class DisplayTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'token_hash' => hash('sha256', Str::random(64)),
            'label' => 'Waiting Room TV',
            'last_seen_at' => null,
            'revoked_at' => null,
        ];
    }
}
