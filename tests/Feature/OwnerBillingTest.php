<?php

use App\Models\Clinic;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('allows platform owners to view a clinic billing page by slug', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create([
        'name' => 'Harbor Clinic',
        'slug' => 'harbor-clinic',
    ]);

    $this->actingAs($owner)
        ->get(route('owner.clinics.show', $clinic))
        ->assertOk()
        ->assertSee('Harbor Clinic')
        ->assertSee(__('Current Subscription'))
        ->assertSee(__('Record Payment'))
        ->assertSee(__('Payment History'));
});

it('includes a clinic details link in the owner clinics table', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create(['slug' => 'harbor-clinic']);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.index')
        ->assertSee(route('owner.clinics.show', $clinic));
});

it('allows platform owners to assign a plan and create an active subscription', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create([
        'price' => '275.50',
        'billing_interval' => 'monthly',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('selectedPlanId', $plan->id)
        ->assertSet('paymentAmount', '275.50')
        ->call('assignPlan')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $subscription = Subscription::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->sole();

    expect($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->status)->toBe('active')
        ->and($subscription->ends_at)->not->toBeNull();

    expect(Payment::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->where('status', 'pending')
        ->count())->toBe(1);
});

it('assigns a plan with Start as Trial creating a trialing subscription and no payment', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create([
        'price' => '350.00',
        'trial_days' => 14,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('selectedPlanId', $plan->id)
        ->set('startAsTrial', true)
        ->call('assignPlan')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $subscription = Subscription::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->sole();

    expect($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->status)->toBe('trialing')
        ->and($subscription->trial_ends_at)->not->toBeNull()
        ->and($subscription->ends_at)->not->toBeNull();

    expect(Payment::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->count())->toBe(0);
});

it('changes a past due subscription back to active and extends ends_at when marking pending payment as paid', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create();

    // Set ends_at in the future (e.g., 3 days from now) to prove it's ignored
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'past_due',
        'ends_at' => now()->addDays(3),
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
        // ends_at must be ~now()+1month, NOT the old ends_at+1month
        ->and($subscription->ends_at->greaterThanOrEqualTo($before->copy()->addMonth()))->toBeTrue()
        ->and($subscription->ends_at->lessThanOrEqualTo($after->copy()->addMonth()))->toBeTrue();
});

it('ends trial and activates subscription when recording payment for a trialing clinic', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['price' => '250.00', 'billing_interval' => 'monthly']);
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'trialing',
        'trial_ends_at' => now()->addDays(10),
        'ends_at' => now()->addDays(10),
    ]);

    $before = now()->startOfSecond();

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('paymentAmount', '250.00')
        ->set('paymentMethod', 'cash')
        ->call('recordPayment')
        ->assertHasNoErrors();

    $subscription->refresh();
    $after = now()->startOfSecond();

    // ends_at must be ~now()+1month, NOT trial_ends_at+1month
    expect($subscription->status)->toBe('active')
        ->and($subscription->trial_ends_at)->toBeNull()
        ->and($subscription->ends_at)->not->toBeNull();

    // ends_at should be between before+1month and after+1month (i.e. based on now())
    expect($subscription->ends_at->greaterThanOrEqualTo($before->copy()->addMonth()))->toBeTrue();
    expect($subscription->ends_at->lessThanOrEqualTo($after->copy()->addMonth()))->toBeTrue();
});

it('records a manual payment against the clinic subscription', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['price' => '300.00']);
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('paymentAmount', '300.25')
        ->set('paymentMethod', 'bank_transfer')
        ->set('paymentReference', 'TRANSFER-001')
        ->set('paymentNotes', 'Payment received at the office.')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show')
        ->assertSee('TRANSFER-001');

    $this->assertDatabaseHas('payments', [
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'amount' => '300.25',
        'currency' => 'MAD',
        'status' => 'paid',
        'method' => 'bank_transfer',
        'reference' => 'TRANSFER-001',
        'notes' => 'Payment received at the office.',
        'recorded_by' => $owner->id,
    ]);

    expect(DB::table('payments')
        ->where('clinic_id', $clinic->id)
        ->where('subscription_id', $subscription->id)
        ->value('paid_at'))->not->toBeNull();
});

it('reactivates a past due subscription and extends its end date after payment', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create();

    // Set ends_at in the future to prove it's ignored
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'past_due',
        'ends_at' => now()->addDays(5),
    ]);

    $before = now()->startOfSecond();

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('paymentAmount', '100.00')
        ->set('paymentMethod', 'cash')
        ->call('recordPayment')
        ->assertHasNoErrors();

    $subscription->refresh();
    $after = now()->startOfSecond();

    expect($subscription->status)->toBe('active')
        ->and($subscription->ends_at->greaterThanOrEqualTo($before->copy()->addMonth()))->toBeTrue()
        ->and($subscription->ends_at->lessThanOrEqualTo($after->copy()->addMonth()))->toBeTrue();

    expect(Payment::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->where('recorded_by', $owner->id)
        ->exists())->toBeTrue();
});

it('renders the payment history table on the clinic page', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();

    $this->actingAs($owner)
        ->get(route('owner.clinics.show', $clinic))
        ->assertOk()
        ->assertSee(__('Payment History'))
        ->assertSee('rounded-2xl');
});

it('dispatches payments-updated after plan assignment and payment recording', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['price' => '200.00']);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('selectedPlanId', $plan->id)
        ->call('assignPlan')
        ->assertDispatched('payments-updated')
        ->set('paymentAmount', '200.00')
        ->set('paymentMethod', 'cash')
        ->call('recordPayment')
        ->assertDispatched('payments-updated');
});

it('deletes pending payments and does not create a new payment when assigning a plan with trial', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['trial_days' => 14]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => null,
        'status' => 'pending',
    ]);

    expect(Payment::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->where('status', 'pending')
        ->count())->toBe(1);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('selectedPlanId', $plan->id)
        ->set('startAsTrial', true)
        ->call('assignPlan')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    expect(Payment::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->where('status', 'pending')
        ->count())->toBe(0);
});

it('validates that selected plan has trial days when start as trial is requested', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['trial_days' => 0]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('selectedPlanId', $plan->id)
        ->set('startAsTrial', true)
        ->call('assignPlan')
        ->assertHasErrors(['startAsTrial']);
});

it('updates existing pending payment and avoids duplicate pending payments when switching to paid plan', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['price' => '250.00']);

    $existingPayment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => null,
        'amount' => '100.00',
        'status' => 'pending',
        'created_at' => now(),
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('selectedPlanId', $plan->id)
        ->set('startAsTrial', false)
        ->call('assignPlan')
        ->assertHasNoErrors();

    $pendingPayments = Payment::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->where('status', 'pending')
        ->get();

    expect($pendingPayments)->toHaveCount(1)
        ->and($pendingPayments->first()->id)->toBe($existingPayment->id)
        ->and((string) $pendingPayments->first()->amount)->toBe('250.00');
});

it('returns correct isLocked status for active, trialing, expired, and cancelled subscriptions', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create();

    // 1. No subscription -> false
    $component = Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic]);
    expect($component->get('isLocked'))->toBeFalse();

    // 2. Active subscription in future -> true
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ends_at' => now()->addMonth(),
    ]);

    $payment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'paid',
        'period_starts_at' => now()->subDay(),
        'period_ends_at' => now()->addMonth(),
    ]);

    $component = Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic]);
    expect($component->get('isLocked'))->toBeTrue();

    // 3. Expired active subscription -> false
    $subscription->update([
        'status' => 'active',
        'ends_at' => now()->subDay(),
    ]);

    $payment->update([
        'period_starts_at' => now()->subMonth(),
        'period_ends_at' => now()->subDay(),
    ]);

    $component = Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic]);
    expect($component->get('isLocked'))->toBeFalse();

    // 4. Trialing subscription in future -> true
    $subscription->update([
        'status' => 'trialing',
        'trial_ends_at' => now()->addDays(7),
        'ends_at' => now()->addDays(7),
    ]);

    $component = Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic]);
    expect($component->get('isLocked'))->toBeTrue();

    // 5. Expired trial subscription -> false
    $subscription->update([
        'status' => 'trialing',
        'trial_ends_at' => now()->subDay(),
        'ends_at' => now()->subDay(),
    ]);

    $component = Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic]);
    expect($component->get('isLocked'))->toBeFalse();

    // 6. Cancelled subscription -> false
    $subscription->update([
        'status' => 'cancelled',
        'ends_at' => now(),
    ]);

    $component = Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic]);
    expect($component->get('isLocked'))->toBeFalse();
});

it('allows platform owner to cancel subscription with unpaid current period, deletes pending payments, and unlocks plan assignment', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create();

    $futureEndsAt = now()->addMonth()->startOfSecond();

    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ends_at' => $futureEndsAt,
    ]);

    $pendingPayment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
        'period_starts_at' => now(),
        'period_ends_at' => $futureEndsAt,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->assertSet('isLocked', true)
        ->call('cancelSubscription')
        ->assertHasNoErrors()
        ->assertSet('isLocked', false)
        ->assertDispatched('toast-show');

    $refreshedSubscription = $subscription->fresh();

    expect($refreshedSubscription->status)->toBe('cancelled')
        ->and($refreshedSubscription->cancelled_at)->not->toBeNull()
        ->and($refreshedSubscription->ends_at->lessThanOrEqualTo(now()))->toBeTrue();

    expect(Payment::withoutGlobalScope('clinic')->whereKey($pendingPayment->id)->exists())->toBeFalse();
});

it('keeps ends_at intact and retains pending prepayments when cancelling a subscription with paid current period', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create();

    $futureEndsAt = now()->addDays(20)->startOfSecond();

    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ends_at' => $futureEndsAt,
    ]);

    $paidPayment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'paid',
        'period_starts_at' => now()->subDays(10),
        'period_ends_at' => $futureEndsAt,
    ]);

    $pendingPrepayment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
        'period_starts_at' => $futureEndsAt,
        'period_ends_at' => $futureEndsAt->copy()->addMonth(),
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->call('cancelSubscription')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $refreshedSubscription = $subscription->fresh();

    expect($refreshedSubscription->status)->toBe('cancelled')
        ->and($refreshedSubscription->cancelled_at)->not->toBeNull()
        ->and($refreshedSubscription->ends_at->equalTo($futureEndsAt))->toBeTrue()
        ->and($refreshedSubscription->ends_at->isFuture())->toBeTrue();

    expect(Payment::withoutGlobalScope('clinic')->whereKey($pendingPrepayment->id)->exists())->toBeTrue();
    expect(Payment::withoutGlobalScope('clinic')->whereKey($paidPayment->id)->exists())->toBeTrue();
});

it('blocks plan assignment when subscription is locked', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create();

    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ends_at' => now()->addMonth(),
    ]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'paid',
        'period_starts_at' => now(),
        'period_ends_at' => now()->addMonth(),
    ]);

    $newPlan = Plan::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('selectedPlanId', $newPlan->id)
        ->call('assignPlan')
        ->assertHasErrors(['selectedPlanId']);
});

it('allows platform owner to toggle clinic active status from clinic details page', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create(['is_active' => true]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->call('toggleClinicStatus')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show')
        ->assertDispatched('clinics-updated');

    expect($clinic->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->call('toggleClinicStatus')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    expect($clinic->fresh()->is_active)->toBeTrue();
});

it('uses yearly billing interval when recording payment during a yearly plan trial', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create([
        'price' => '1200.00',
        'billing_interval' => 'yearly',
        'trial_days' => 30,
    ]);
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'trialing',
        'trial_ends_at' => now()->addDays(15),
        'ends_at' => now()->addDays(15),
    ]);

    $before = now()->startOfSecond();

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('paymentAmount', '1200.00')
        ->set('paymentMethod', 'cash')
        ->call('recordPayment')
        ->assertHasNoErrors();

    $subscription->refresh();
    $after = now()->startOfSecond();

    expect($subscription->status)->toBe('active')
        ->and($subscription->trial_ends_at)->toBeNull();

    // Must be ~now()+1year, NOT trial_ends_at+1year
    expect($subscription->ends_at->greaterThanOrEqualTo($before->copy()->addYear()))->toBeTrue();
    expect($subscription->ends_at->lessThanOrEqualTo($after->copy()->addYear()))->toBeTrue();
});

it('activates a past_due subscription from now when ends_at is already in the past', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'past_due',
        'ends_at' => now()->subDays(5), // Already expired
    ]);

    $before = now()->startOfSecond();

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('paymentAmount', '100.00')
        ->set('paymentMethod', 'cash')
        ->call('recordPayment')
        ->assertHasNoErrors();

    $subscription->refresh();
    $after = now()->startOfSecond();

    expect($subscription->status)->toBe('active');

    // Base must be now(), not the already-expired ends_at
    expect($subscription->ends_at->greaterThanOrEqualTo($before->copy()->addMonth()))->toBeTrue();
    expect($subscription->ends_at->lessThanOrEqualTo($after->copy()->addMonth()))->toBeTrue();
});

it('ends trial and uses billing_interval when marking pending payment as paid from payments page', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'yearly']);
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'trialing',
        'trial_ends_at' => now()->addDays(7),
        'ends_at' => now()->addDays(7),
    ]);

    $payment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
        'method' => null,
        'period_starts_at' => null,
        'period_ends_at' => null,
    ]);

    $before = now()->startOfSecond();

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openMarkAsPaid', $payment->id)
        ->set('paymentMethod', 'cash')
        ->call('markAsPaid')
        ->assertHasNoErrors();

    $subscription->refresh();
    $after = now()->startOfSecond();

    expect($subscription->status)->toBe('active')
        ->and($subscription->trial_ends_at)->toBeNull();

    // Must be ~now()+1year (yearly interval), starting from now() not trial_ends_at
    expect($subscription->ends_at->greaterThanOrEqualTo($before->copy()->addYear()))->toBeTrue();
    expect($subscription->ends_at->lessThanOrEqualTo($after->copy()->addYear()))->toBeTrue();
});

it('reactivates a cancelled subscription and extends ends_at from now after recording payment on clinic page', function () {
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

    $before = now()->startOfSecond();

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('paymentAmount', '150.00')
        ->set('paymentMethod', 'cash')
        ->call('recordPayment')
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

it('does not extend ends_at when recording payment for an active subscription with future ends_at on clinic page', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);
    $startsAt = now()->startOfSecond();
    $futureEndsAt = now()->addDays(15)->startOfSecond();
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => $startsAt,
        'ends_at' => $futureEndsAt,
    ]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
        'method' => null,
        'period_starts_at' => $startsAt,
        'period_ends_at' => $futureEndsAt,
        'created_at' => $startsAt,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('paymentAmount', '150.00')
        ->set('paymentMethod', 'cash')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $subscription->refresh();

    // Ends at must NOT be extended (initial invoice for current cycle)
    expect($subscription->status)->toBe('active')
        ->and($subscription->ends_at->equalTo($futureEndsAt))->toBeTrue();
});

it('extends ends_at when recording a prepayment for an active subscription on clinic page', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);
    $startsAt = now()->subDays(10)->startOfSecond();
    $futureEndsAt = now()->addDays(20)->startOfSecond();
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => $startsAt,
        'ends_at' => $futureEndsAt,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('paymentAmount', '150.00')
        ->set('paymentMethod', 'cash')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $subscription->refresh();

    // Since this payment was created while subscription was active (created_at > starts_at), it extends ends_at
    expect($subscription->status)->toBe('active')
        ->and($subscription->ends_at->equalTo($futureEndsAt->copy()->addMonth()))->toBeTrue();
});

it('does not extend ends_at when marking the first pending payment as paid for a newly active subscription', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create([
        'billing_interval' => 'monthly',
        'price' => '200.00',
        'is_active' => true,
    ]);

    // 1. Assign new plan without trial -> creates active subscription + pending payment
    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('selectedPlanId', $plan->id)
        ->set('startAsTrial', false)
        ->call('assignPlan')
        ->assertHasNoErrors();

    $subscription = Subscription::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->sole();

    expect($subscription->status)->toBe('active');
    $originalEndsAt = $subscription->ends_at;

    $pendingPayment = Payment::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->where('status', 'pending')
        ->sole();

    // 2. Mark the first pending payment as paid -> must NOT extend ends_at again
    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openMarkAsPaid', $pendingPayment->id)
        ->set('paymentMethod', 'cash')
        ->call('markAsPaid')
        ->assertHasNoErrors();

    $subscription->refresh();
    $pendingPayment->refresh();

    expect($pendingPayment->status)->toBe('paid')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->ends_at->equalTo($originalEndsAt))->toBeTrue();
});

it('calculates the current billing period correctly when prepayment extends ends_at into the future', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);

    $currentEnd = now()->addDays(20)->startOfSecond();
    $currentStart = $currentEnd->copy()->subMonth();
    $prepaidEndsAt = $currentEnd->copy()->addMonth();

    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => $currentStart,
        'ends_at' => $prepaidEndsAt,
    ]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'paid',
        'period_starts_at' => $currentStart,
        'period_ends_at' => $currentEnd,
        'created_at' => now()->subDays(5),
    ]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'paid',
        'period_starts_at' => $currentEnd,
        'period_ends_at' => $prepaidEndsAt,
        'created_at' => now()->subDays(2),
    ]);

    $expectedStart = $currentStart->format('M j, Y');
    $expectedEnd = $currentEnd->format('M j, Y');
    $expectedNextEnd = $prepaidEndsAt->format('M j, Y');

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->assertSee($expectedStart)
        ->assertSee($expectedEnd)
        ->assertSee($expectedNextEnd)
        ->assertSee(__('Next Billing Period'))
        ->assertSee(__('Prepaid'))
        ->assertSee(__('Paid'));
});

it('displays pending invoice badge when a pending payment exists for the current period', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);

    $endsAt = now()->addDays(15)->startOfSecond();
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => $endsAt->copy()->subMonth(),
        'ends_at' => $endsAt,
    ]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
        'period_starts_at' => $endsAt->copy()->subMonth(),
        'period_ends_at' => $endsAt,
        'created_at' => now()->subDays(2),
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->assertSee(__('Pending Invoice'));
});

it('displays unpaid badge when no payment exists for the current period', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);

    $endsAt = now()->addDays(15)->startOfSecond();
    Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => $endsAt->copy()->subMonth(),
        'ends_at' => $endsAt,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->assertSee(__('Unpaid'));
});

it('marks the current period as paid when covered by a previous prepayment made in an earlier period', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);

    $startsAt = now()->subDays(40)->startOfSecond();
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMonths(2),
    ]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'paid',
        'period_starts_at' => $startsAt,
        'period_ends_at' => $startsAt->copy()->addMonth(),
        'created_at' => $startsAt->copy()->addMinute(),
    ]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'paid',
        'period_starts_at' => $startsAt->copy()->addMonth(),
        'period_ends_at' => $startsAt->copy()->addMonths(2),
        'created_at' => $startsAt->copy()->addMinutes(5),
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->assertSee(__('Paid'));
});

it('displays current billing period when subscription is cancelled but ends_at is in the future', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);

    $futureEndsAt = now()->addDays(20)->startOfSecond();
    $expectedStart = $futureEndsAt->copy()->subMonth()->format('M j, Y');
    $expectedEnd = $futureEndsAt->format('M j, Y');

    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'cancelled',
        'cancelled_at' => now(),
        'starts_at' => $futureEndsAt->copy()->subMonth(),
        'ends_at' => $futureEndsAt,
    ]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'paid',
        'period_starts_at' => $futureEndsAt->copy()->subMonth(),
        'period_ends_at' => $futureEndsAt,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->assertSee(__('Current Billing Period'))
        ->assertSee($expectedStart)
        ->assertSee($expectedEnd);
});

it('displays billed period for payments in the payment history table', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);

    $startsAt = now()->subDays(10)->startOfSecond();
    $endsAt = $startsAt->copy()->addMonth();

    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'paid',
        'amount' => '100.00',
        'period_starts_at' => $startsAt,
        'period_ends_at' => $endsAt,
        'created_at' => $startsAt,
        'paid_at' => $startsAt->copy()->addMinutes(5),
    ]);

    $expectedPeriod = $startsAt->format('M j, Y').' - '.$endsAt->format('M j, Y');

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->assertSee(__('Billed Period'))
        ->assertSee($expectedPeriod);
});

it('correctly sets period dates on prepayment using createPayment and extends ends_at when marked as paid', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['price' => '300.00', 'billing_interval' => 'monthly']);

    $currentEndsAt = now()->addDays(15)->startOfSecond();

    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ends_at' => $currentEndsAt,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->set('createClinicId', $clinic->id)
        ->set('createPaymentAmount', '300.00')
        ->call('createPayment')
        ->assertHasNoErrors();

    $pendingPayment = Payment::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->where('status', 'pending')
        ->sole();

    expect($pendingPayment->period_starts_at->equalTo($currentEndsAt))->toBeTrue()
        ->and($pendingPayment->period_ends_at->equalTo($currentEndsAt->copy()->addMonth()))->toBeTrue();

    Livewire::actingAs($owner)
        ->test('pages::owner.payments.index')
        ->call('openMarkAsPaid', $pendingPayment->id)
        ->set('paymentMethod', 'cash')
        ->call('markAsPaid')
        ->assertHasNoErrors();

    $subscription->refresh();
    expect($subscription->ends_at->equalTo($pendingPayment->period_ends_at))->toBeTrue()
        ->and($subscription->starts_at->equalTo($pendingPayment->period_starts_at))->toBeTrue();
});

it('updates starts_at when a new payment is made while preserving original created_at', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['price' => '200.00', 'billing_interval' => 'monthly']);

    $firstSubscribedAt = now()->subMonths(2)->startOfSecond();
    $currentPeriodStart = now()->subDays(10)->startOfSecond();
    $currentPeriodEnd = now()->addDays(20)->startOfSecond();

    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => $currentPeriodStart,
        'ends_at' => $currentPeriodEnd,
        'created_at' => $firstSubscribedAt,
        'updated_at' => $firstSubscribedAt,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('paymentAmount', '200.00')
        ->set('paymentMethod', 'cash')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $subscription->refresh();

    // starts_at must roll forward to the new prepayment period start ($currentPeriodEnd)
    expect($subscription->starts_at->equalTo($currentPeriodEnd))->toBeTrue()
        ->and($subscription->ends_at->equalTo($currentPeriodEnd->copy()->addMonth()))->toBeTrue()
        // created_at must remain the original subscription timestamp
        ->and($subscription->created_at->equalTo($firstSubscribedAt))->toBeTrue();
});

it('displays Assigned At and Current Billing Period in the clinic subscription card', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);

    $firstSubscribedAt = now()->subMonths(3)->startOfSecond();
    $currentPeriodStart = now()->subDays(5)->startOfSecond();
    $currentPeriodEnd = now()->addDays(25)->startOfSecond();

    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => $currentPeriodStart,
        'ends_at' => $currentPeriodEnd,
        'created_at' => $firstSubscribedAt,
    ]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'paid',
        'period_starts_at' => $currentPeriodStart,
        'period_ends_at' => $currentPeriodEnd,
    ]);

    $expectedAssignedAt = $firstSubscribedAt->format('M j, Y');
    $expectedPeriodStart = $currentPeriodStart->format('M j, Y');
    $expectedPeriodEnd = $currentPeriodEnd->format('M j, Y');

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->assertSee(__('Assigned At'))
        ->assertSee($expectedAssignedAt)
        ->assertSee(__('Current Billing Period'))
        ->assertSee($expectedPeriodStart)
        ->assertSee($expectedPeriodEnd);
});

it('blocks opening create payment modal when clinic already has a pending invoice', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly']);

    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ends_at' => now()->addMonth(),
    ]);

    Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->call('openCreatePayment')
        ->assertHasErrors(['createPaymentAmount' => 'This clinic already has a pending invoice. Please mark it as paid first.']);
});

it('blocks creating a payment invoice when clinic is trialing', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => 'monthly', 'trial_days' => 14]);

    Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'trialing',
        'trial_ends_at' => now()->addDays(14),
        'ends_at' => now()->addDays(14),
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->call('openCreatePayment')
        ->assertHasErrors(['createPaymentAmount' => 'Cannot create payment for a trialing clinic.']);
});

it('creates a pending payment invoice from the clinic details modal and allows marking as paid', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create(['price' => '350.00', 'billing_interval' => 'monthly']);

    $currentEndsAt = now()->addDays(10)->startOfSecond();

    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ends_at' => $currentEndsAt,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->call('openCreatePayment')
        ->assertSet('createPaymentAmount', '350.00')
        ->set('createPaymentNotes', 'Prepayment recorded via modal.')
        ->call('createPayment')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show')
        ->assertDispatched('payments-updated');

    $pendingPayment = Payment::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->where('status', 'pending')
        ->sole();

    expect($pendingPayment->amount)->toBe('350.00')
        ->and($pendingPayment->notes)->toBe('Prepayment recorded via modal.')
        ->and($pendingPayment->period_starts_at->equalTo($currentEndsAt))->toBeTrue()
        ->and($pendingPayment->period_ends_at->equalTo($currentEndsAt->copy()->addMonth()))->toBeTrue();

    // Now mark as paid via modal workflow
    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->call('openMarkAsPaid', $pendingPayment->id)
        ->set('paymentMethod', 'cash')
        ->call('markAsPaid')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $subscription->refresh();
    $pendingPayment->refresh();

    expect($pendingPayment->status)->toBe('paid')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->starts_at->equalTo($currentEndsAt))->toBeTrue()
        ->and($subscription->ends_at->equalTo($pendingPayment->period_ends_at))->toBeTrue();
});

it('handles payment mark and delete request events with both id and paymentId parameter payloads', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'status' => 'active',
        'ends_at' => now()->addMonth(),
    ]);

    $pendingPayment = Payment::factory()->create([
        'clinic_id' => $clinic->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
    ]);

    // Dispatch event with { id: ... } payload (as emitted by JS templates)
    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->dispatch('payment-mark-requested', id: $pendingPayment->id)
        ->assertSet('paymentId', $pendingPayment->id)
        ->assertHasNoErrors();

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->dispatch('payment-delete-requested', id: $pendingPayment->id)
        ->assertSet('deletingPaymentId', $pendingPayment->id)
        ->assertHasNoErrors();
});
