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
    $subscription = Subscription::factory()->create([
        'plan_id' => $this->plan->id,
        'status' => 'active',
        'ends_at' => now()->subDay(),
    ]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('billing.suspended'));

    $this->clinic->refresh();

    expect($this->clinic->subscription->status)->toBe('past_due');
});

it('blocks access and sets status to past due when trial ends at is in the past', function () {
    $subscription = Subscription::factory()->create([
        'plan_id' => $this->plan->id,
        'status' => 'trialing',
        'trial_ends_at' => now()->subDay(),
    ]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('billing.suspended'));

    $this->clinic->refresh();

    expect($this->clinic->subscription->status)->toBe('past_due');
});

it('correctly reports subscription state via model helpers', function () {
    $activeSub = Subscription::factory()->make([
        'status' => 'active',
        'ends_at' => now()->addMonth(),
    ]);
    expect($activeSub->isActive())->toBeTrue()
        ->and($activeSub->isTrialing())->toBeFalse()
        ->and($activeSub->isPastDue())->toBeFalse();

    $trialSub = Subscription::factory()->make([
        'status' => 'trialing',
        'trial_ends_at' => now()->addDays(7),
    ]);
    expect($trialSub->isTrialing())->toBeTrue()
        ->and($trialSub->isActive())->toBeFalse()
        ->and($trialSub->isPastDue())->toBeFalse();

    $expiredTrialSub = Subscription::factory()->make([
        'status' => 'trialing',
        'trial_ends_at' => now()->subDay(),
    ]);
    expect($expiredTrialSub->isPastDue())->toBeTrue()
        ->and($expiredTrialSub->isTrialing())->toBeFalse();

    $expiredActiveSub = Subscription::factory()->make([
        'status' => 'active',
        'ends_at' => now()->subDay(),
    ]);
    expect($expiredActiveSub->isPastDue())->toBeTrue()
        ->and($expiredActiveSub->isActive())->toBeFalse();

    $pastDueSub = Subscription::factory()->make([
        'status' => 'past_due',
    ]);
    expect($pastDueSub->isPastDue())->toBeTrue();

    $gracePeriodSub = Subscription::factory()->make([
        'status' => 'cancelled',
        'ends_at' => now()->addMonth(),
        'cancelled_at' => now(),
    ]);
    expect($gracePeriodSub->onGracePeriod())->toBeTrue()
        ->and($gracePeriodSub->isActive())->toBeFalse()
        ->and($gracePeriodSub->isPastDue())->toBeFalse();

    $expiredCancelledSub = Subscription::factory()->make([
        'status' => 'cancelled',
        'ends_at' => now()->subDay(),
        'cancelled_at' => now()->subDays(10),
    ]);
    expect($expiredCancelledSub->onGracePeriod())->toBeFalse()
        ->and($expiredCancelledSub->isActive())->toBeFalse();
});

it('allows access when subscription is cancelled but ends_at is in the future', function () {
    Subscription::factory()->create([
        'plan_id' => $this->plan->id,
        'status' => 'cancelled',
        'ends_at' => now()->addDays(10),
        'cancelled_at' => now(),
    ]);

    $this->get(route('dashboard'))->assertOk();
});

it('blocks access when subscription is cancelled and ends_at is in the past', function () {
    Subscription::factory()->create([
        'plan_id' => $this->plan->id,
        'status' => 'cancelled',
        'ends_at' => now()->subDay(),
        'cancelled_at' => now()->subDays(10),
    ]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('billing.suspended'));

    $this->clinic->refresh();

    expect($this->clinic->subscription->status)->toBe('past_due');
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

it('blocks clinic admin access when the clinic is suspended even with an active subscription', function () {
    Subscription::factory()->create([
        'plan_id' => $this->plan->id,
        'status' => 'active',
    ]);

    $this->clinic->update(['is_active' => false]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('billing.suspended'))
        ->assertSessionHas('clinic_suspended', true);
});

it('unblocks clinic admin access when the clinic is reactivated', function () {
    Subscription::factory()->create([
        'plan_id' => $this->plan->id,
        'status' => 'active',
    ]);

    // Clinic is suspended -> blocked
    $this->clinic->update(['is_active' => false]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('billing.suspended'));

    // Clinic is activated -> unblocked
    $this->clinic->update(['is_active' => true]);

    $this->get(route('dashboard'))
        ->assertOk();
});
