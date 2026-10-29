<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::create([
            'name' => 'Trial Plan',
            'slug' => 'trial',
            'price' => 0.00,
            'billing_interval' => 'monthly',
            'trial_days' => 14,
            'is_active' => true,
        ]);

        Plan::create([
            'name' => 'Basic Plan',
            'slug' => 'basic',
            'price' => 300.00,
            'billing_interval' => 'monthly',
            'trial_days' => 0,
            'is_active' => true,
        ]);

        Plan::create([
            'name' => 'Pro Yearly',
            'slug' => 'pro-yearly',
            'price' => 3000.00,
            'billing_interval' => 'yearly',
            'trial_days' => 0,
            'is_active' => true,
        ]);
    }
}
