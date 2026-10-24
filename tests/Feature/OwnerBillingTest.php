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

it('includes a clinic details link in the owner clinics table data', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create(['slug' => 'harbor-clinic']);

    $this->actingAs($owner)
        ->getJson(route('owner.clinics.data'))
        ->assertOk()
        ->assertJsonPath('data.0.billing_url', route('owner.clinics.show', $clinic))
        ->assertJsonPath('data.0.details_title', __('View clinic details and billing'));
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
    $endDate = now()->addDays(5)->startOfSecond();
    $subscription = Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'past_due',
        'ends_at' => $endDate,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.clinics.show', ['clinic' => $clinic])
        ->set('paymentAmount', '100.00')
        ->set('paymentMethod', 'cash')
        ->call('recordPayment')
        ->assertHasNoErrors();

    $subscription->refresh();

    expect($subscription->status)->toBe('active')
        ->and($subscription->ends_at?->toDateTimeString())
        ->toBe($endDate->addMonth()->toDateTimeString());

    expect(Payment::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->where('recorded_by', $owner->id)
        ->exists())->toBeTrue();
});
