<?php

use App\Models\Clinic;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Livewire\Livewire;

it('creates a pending payment when a platform owner changes a clinic plan', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['price' => '425.50']);
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'status' => 'trialing',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('selectedPlanId', $plan->id)
        ->call('assignPlan')
        ->assertHasNoErrors();

    $subscription->refresh();
    $payment = Payment::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->sole();

    expect($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->status)->toBe('active')
        ->and($payment->subscription_id)->toBe($subscription->id)
        ->and($payment->amount)->toBe('425.50')
        ->and($payment->status)->toBe('pending')
        ->and($payment->method)->toBeNull()
        ->and($payment->reference)->toBeNull()
        ->and($payment->recorded_by)->toBe($owner->id);
});

it('marks a pending payment as paid and reactivates and extends its trial subscription', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'past_due',
        'ends_at' => now()->addDays(3), // Future date, but should be ignored
    ]);

    $payment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
        'method' => null,
    ]);

    $before = now()->startOfSecond();

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openMarkAsPaid', $payment->id)
        ->set('paymentMethod', 'cash')
        ->call('markAsPaid')
        ->assertHasNoErrors();

    $subscription->refresh();
    $payment->refresh();
    $after = now()->startOfSecond();

    expect($payment->status)->toBe('paid')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->ends_at->greaterThanOrEqualTo($before->copy()->addMonth()))->toBeTrue()
        ->and($subscription->ends_at->lessThanOrEqualTo($after->copy()->addMonth()))->toBeTrue();
});

it('requires a bank transfer reference before marking a payment as paid', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
    ]);
    $payment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
        'method' => null,
        'paid_at' => null,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openMarkAsPaid', $payment->id)
        ->set('paymentMethod', 'bank_transfer')
        ->call('markAsPaid')
        ->assertHasErrors(['paymentReference' => 'required']);

    expect($payment->fresh()->status)->toBe('pending');
});

it('creates a pending payment invoice from the global payments page', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'status' => 'past_due',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openCreatePayment')
        ->set('createClinicId', $clinic->id)
        ->set('createPaymentAmount', '225.75')
        ->set('createPaymentNotes', 'Recorded from payments.')
        ->call('createPayment')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $payment = Payment::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->sole();

    expect($payment->subscription_id)->toBe($subscription->id)
        ->and($payment->amount)->toBe('225.75')
        ->and($payment->status)->toBe('pending')
        ->and($payment->method)->toBeNull()
        ->and($payment->reference)->toBeNull()
        ->and($payment->paid_at)->toBeNull()
        ->and($payment->notes)->toBe('Recorded from payments.')
        ->and($payment->recorded_by)->toBe($owner->id);
});

it('lists global payments with status filtering', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $pendingClinic = Clinic::factory()->create(['name' => 'Pending Clinic']);
    $paidClinic = Clinic::factory()->create(['name' => 'Paid Clinic']);
    $pendingSubscription = Subscription::factory()->create(['clinic_id' => $pendingClinic->id]);
    $paidSubscription = Subscription::factory()->create(['clinic_id' => $paidClinic->id]);

    Payment::factory()->create([
        'clinic_id' => $pendingClinic->id,
        'subscription_id' => $pendingSubscription->id,
        'status' => 'pending',
        'method' => null,
    ]);
    Payment::factory()->create([
        'clinic_id' => $paidClinic->id,
        'subscription_id' => $paidSubscription->id,
        'status' => 'paid',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->assertSeeHtml(route('owner.clinics.show', $pendingClinic))
        ->assertSeeHtml(route('owner.clinics.show', $paidClinic))
        ->set('statusFilter', 'pending')
        ->assertSeeHtml(route('owner.clinics.show', $pendingClinic))
        ->assertDontSeeHtml(route('owner.clinics.show', $paidClinic));

    $this->actingAs($owner)->get(route('owner.payments.index'))
        ->assertOk()
        ->assertSee(__('Create Payment'));
});

it('blocks creating a payment invoice when the clinic already has a pending payment', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'status' => 'active',
    ]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
        'method' => null,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openCreatePayment')
        ->set('createClinicId', $clinic->id)
        ->set('createPaymentAmount', '225.75')
        ->call('createPayment')
        ->assertHasErrors(['createClinicId' => __('This clinic already has a pending invoice. Please mark it as paid first.')]);

    expect(Payment::withoutGlobalScope('clinic')->where('clinic_id', $clinic->id)->count())->toBe(1);
});

it('fetches clinics with active, past_due, or cancelled subscriptions and excludes trialing and no-subscription clinics in global payment creation modal', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);

    // 1. Clinic with valid, active, non-trial subscription -> should be INCLUDED
    $activePlan = Plan::factory()->create(['slug' => 'pro', 'is_active' => true]);
    $validClinic = Clinic::factory()->create(['name' => 'Valid Active Clinic']);
    Subscription::factory()->create([
        'clinic_id' => $validClinic->id,
        'plan_id' => $activePlan->id,
        'status' => 'active',
        'ends_at' => now()->addMonth(),
    ]);

    // 2. Clinic with default trial plan -> should be EXCLUDED
    $trialPlan = Plan::factory()->create(['slug' => 'trial', 'is_active' => true]);
    $trialClinic = Clinic::factory()->create(['name' => 'Trial Clinic']);
    Subscription::factory()->create([
        'clinic_id' => $trialClinic->id,
        'plan_id' => $trialPlan->id,
        'status' => 'trialing',
        'ends_at' => now()->addDays(14),
    ]);

    // 3. Clinic with trialing status on active plan -> should be EXCLUDED
    $trialingClinic = Clinic::factory()->create(['name' => 'Trialing Status Clinic']);
    Subscription::factory()->create([
        'clinic_id' => $trialingClinic->id,
        'plan_id' => $activePlan->id,
        'status' => 'trialing',
        'ends_at' => now()->addDays(14),
    ]);

    // 4. Clinic with cancelled subscription -> should be INCLUDED
    $cancelledClinic = Clinic::factory()->create(['name' => 'Cancelled Clinic']);
    Subscription::factory()->create([
        'clinic_id' => $cancelledClinic->id,
        'plan_id' => $activePlan->id,
        'status' => 'cancelled',
        'ends_at' => now()->subDay(),
    ]);

    // 5. Clinic with past_due subscription -> should be INCLUDED
    $pastDueClinic = Clinic::factory()->create(['name' => 'Past Due Clinic']);
    Subscription::factory()->create([
        'clinic_id' => $pastDueClinic->id,
        'plan_id' => $activePlan->id,
        'status' => 'past_due',
        'ends_at' => now()->subDay(),
    ]);

    // 6. Clinic without any subscription -> should be EXCLUDED
    $noSubClinic = Clinic::factory()->create(['name' => 'No Sub Clinic']);

    $component = Livewire::actingAs($owner)
        ->test('pages::owner.payments.index');

    $clinics = $component->instance()->clinics;

    expect($clinics->pluck('id'))->toContain($validClinic->id)
        ->toContain($cancelledClinic->id)
        ->toContain($pastDueClinic->id)
        ->not->toContain($trialClinic->id)
        ->not->toContain($trialingClinic->id)
        ->not->toContain($noSubClinic->id);
});

it('filters payments by clinic in the clinic show page', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinicA = Clinic::factory()->create(['name' => 'Clinic A']);
    $clinicB = Clinic::factory()->create(['name' => 'Clinic B']);
    $subA = Subscription::factory()->create(['clinic_id' => $clinicA->id]);
    $subB = Subscription::factory()->create(['clinic_id' => $clinicB->id]);

    Payment::factory()->create([
        'clinic_id' => $clinicA->id,
        'subscription_id' => $subA->id,
        'amount' => '150.00',
    ]);
    Payment::factory()->create([
        'clinic_id' => $clinicB->id,
        'subscription_id' => $subB->id,
        'amount' => '350.00',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinicA])
        ->assertSee('150.00 MAD')
        ->assertDontSee('350.00 MAD');
});

it('renders page head and content in cards with rounded-2xl across clinics, plans, and payments pages', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);

    $this->actingAs($owner)->get(route('owner.clinics.index'))
        ->assertOk()
        ->assertSee('rounded-2xl')
        ->assertSee(__('Create Clinic'));

    $this->actingAs($owner)->get(route('owner.plans.index'))
        ->assertOk()
        ->assertSee('rounded-2xl')
        ->assertSee(__('Create Plan'));

    $this->actingAs($owner)->get(route('owner.payments.index'))
        ->assertOk()
        ->assertSee('rounded-2xl')
        ->assertSee(__('Create Payment'));
});

it('shows mark as paid and delete actions for pending payments and hides them for paid payments', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create(['name' => 'City Clinic']);
    $sub = Subscription::factory()->create(['clinic_id' => $clinic->id]);

    $pendingPayment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $sub->id,
        'status' => 'pending',
    ]);

    $paidPayment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $sub->id,
        'status' => 'paid',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->set('statusFilter', 'pending')
        ->assertSeeHtml('openMarkAsPaid('.$pendingPayment->id.')')
        ->assertSeeHtml('openDeletePayment('.$pendingPayment->id.')')
        ->set('statusFilter', 'paid')
        ->assertDontSeeHtml('openMarkAsPaid('.$paidPayment->id.')');
});

it('allows platform owner to delete a pending or failed payment', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $sub = Subscription::factory()->create(['clinic_id' => $clinic->id]);

    $pendingPayment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $sub->id,
        'status' => 'pending',
    ]);

    $failedPayment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $sub->id,
        'status' => 'failed',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openDeletePayment', $pendingPayment->id)
        ->assertSet('deletingPaymentId', $pendingPayment->id)
        ->call('deletePayment')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show')
        ->assertDispatched('payments-updated');

    expect(Payment::withoutGlobalScope('clinic')->whereKey($pendingPayment->id)->exists())->toBeFalse();

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openDeletePayment', $failedPayment->id)
        ->assertSet('deletingPaymentId', $failedPayment->id)
        ->call('deletePayment')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    expect(Payment::withoutGlobalScope('clinic')->whereKey($failedPayment->id)->exists())->toBeFalse();
});

it('prevents deleting a paid payment', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $sub = Subscription::factory()->create(['clinic_id' => $clinic->id]);

    $paidPayment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $sub->id,
        'status' => 'paid',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openDeletePayment', $paidPayment->id)
        ->assertHasErrors(['paymentId']);

    expect(Payment::withoutGlobalScope('clinic')->whereKey($paidPayment->id)->exists())->toBeTrue();
});

it('reactivates a cancelled subscription and sets ends_at from now when marking payment as paid from global payments', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'cancelled',
        'ends_at' => now()->subDay(),
        'cancelled_at' => now()->subDay(),
    ]);

    $payment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
        'method' => null,
    ]);

    $before = now()->startOfSecond();

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openMarkAsPaid', $payment->id)
        ->set('paymentMethod', 'cash')
        ->call('markAsPaid')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $subscription->refresh();
    $after = now()->startOfSecond();

    expect($subscription->status)->toBe('active')
        ->and($subscription->cancelled_at)->toBeNull()
        ->and($subscription->trial_ends_at)->toBeNull()
        ->and($subscription->ends_at->greaterThanOrEqualTo($before->copy()->addMonth()))->toBeTrue()
        ->and($subscription->ends_at->lessThanOrEqualTo($after->copy()->addMonth()))->toBeTrue();
});

it('does not extend ends_at when marking the first payment as paid for an active subscription from global payments', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);
    $startsAt = now()->startOfSecond();
    $futureEndsAt = $startsAt->copy()->addMonth();
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => $startsAt,
        'ends_at' => $futureEndsAt,
    ]);

    $payment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
        'method' => null,
        'period_starts_at' => $startsAt,
        'period_ends_at' => $futureEndsAt,
        'created_at' => $startsAt,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openMarkAsPaid', $payment->id)
        ->set('paymentMethod', 'cash')
        ->call('markAsPaid')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $subscription->refresh();

    // Ends at must NOT be extended (initial invoice for period already generated)
    expect($subscription->status)->toBe('active')
        ->and($subscription->ends_at->equalTo($futureEndsAt))->toBeTrue();
});

it('extends ends_at from future ends_at when marking a prepayment as paid for an active subscription from global payments', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);
    $startsAt = now()->subDays(10)->startOfSecond();
    $currentEndsAt = now()->addDays(20)->startOfSecond();
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => $startsAt,
        'ends_at' => $currentEndsAt,
    ]);

    $prepayment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
        'method' => null,
        'period_starts_at' => $currentEndsAt,
        'period_ends_at' => $currentEndsAt->copy()->addMonth(),
        'created_at' => now()->startOfSecond(),
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openMarkAsPaid', $prepayment->id)
        ->set('paymentMethod', 'cash')
        ->call('markAsPaid')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $subscription->refresh();

    // Prepayment extends ends_at and rolls starts_at forward to payment period_starts_at
    expect($subscription->status)->toBe('active')
        ->and($subscription->ends_at->equalTo($currentEndsAt->copy()->addMonth()))->toBeTrue()
        ->and($subscription->starts_at->equalTo($currentEndsAt))->toBeTrue();
});

it('extends ends_at starting from now when marking payment as paid for a past_due subscription from global payments', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'past_due',
        'ends_at' => now()->subDays(5),
    ]);

    $payment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
        'method' => null,
    ]);

    $before = now()->startOfSecond();

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openMarkAsPaid', $payment->id)
        ->set('paymentMethod', 'cash')
        ->call('markAsPaid')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $subscription->refresh();
    $after = now()->startOfSecond();

    expect($subscription->status)->toBe('active')
        ->and($subscription->ends_at->greaterThanOrEqualTo($before->copy()->addMonth()))->toBeTrue()
        ->and($subscription->ends_at->lessThanOrEqualTo($after->copy()->addMonth()))->toBeTrue();
});
