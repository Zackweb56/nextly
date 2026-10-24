<?php

use App\Models\Clinic;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentClinic;

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->plan = Plan::factory()->create();
    $this->user = User::factory()->create();
    $this->user->clinics()->attach($this->clinic->id, [
        'role' => 'clinic_admin',
        'is_active' => true,
    ]);
    $this->actingAs($this->user);
    CurrentClinic::set($this->clinic->id);
});

it('allows access with an active subscription', function () {
    Subscription::factory()->create([
        'plan_id' => $this->plan->id,
        'status' => 'active',
    ]);

    $this->get(route('dashboard'))->assertOk();
});

it('allows access with a trialing subscription', function () {
    Subscription::factory()->create([
        'plan_id' => $this->plan->id,
        'status' => 'trialing',
        'trial_ends_at' => now()->addDay(),
    ]);

    $this->get(route('dashboard'))->assertOk();
});

it('blocks access when the subscription is past due', function () {
    Subscription::factory()->create([
        'plan_id' => $this->plan->id,
        'status' => 'past_due',
    ]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('billing.suspended'));
});

it('blocks access when an active subscription has expired', function () {
    Subscription::factory()->create([
        'plan_id' => $this->plan->id,
        'status' => 'active',
        'ends_at' => now()->subDay(),
    ]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('billing.suspended'));
});

it('blocks access when the clinic has no subscription', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('billing.suspended'));
});

it('allows clinic staff to view the suspended billing page', function () {
    $this->get(route('billing.suspended'))->assertOk();
});

it('allows platform owners through without an active subscription', function () {
    $this->user->forceFill(['is_platform_owner' => true])->save();

    $this->get(route('dashboard'))
        ->assertRedirect(route('owner.dashboard'));
});
