<?php

use App\Models\Clinic;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;

it('attaches a subscription to a clinic', function () {
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['name' => 'Pro', 'price' => 300]);

    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    expect($clinic->subscription->id)->toBe($subscription->id);
    expect($clinic->subscription->plan->name)->toBe('Pro');
});

it('can record a pending payment for a subscription', function () {
    $subscription = Subscription::factory()->create();

    $payment = Payment::factory()->create([
        'clinic_id' => $subscription->clinic_id,
        'subscription_id' => $subscription->id,
        'amount' => 300,
        'status' => 'pending',
        'method' => null, // Not paid yet
        'paid_at' => null,
    ]);

    expect($subscription->payments)->toHaveCount(1);
    expect($payment->status)->toBe('pending');
    expect($payment->amount)->toBe('300.00');
});
