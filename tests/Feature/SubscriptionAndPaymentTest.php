<?php

use App\Models\Clinic;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentClinic;

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->user = User::factory()->create();
    $this->user->clinics()->attach($this->clinic->id, [
        'role' => 'clinic_admin',
        'is_active' => true,
    ]);
    $this->actingAs($this->user);
    CurrentClinic::set($this->clinic->id);
});

it('attaches a subscription to a clinic', function () {
    $plan = Plan::factory()->create(['name' => 'Pro', 'price' => 300]);

    $subscription = Subscription::factory()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    expect($this->clinic->subscription->id)->toBe($subscription->id);
    expect($this->clinic->subscription->plan->name)->toBe('Pro');
});

it('can record a pending payment for a subscription', function () {
    $subscription = Subscription::factory()->create();

    $payment = Payment::factory()->create([
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
