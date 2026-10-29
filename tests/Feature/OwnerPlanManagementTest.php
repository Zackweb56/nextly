<?php

use App\Models\Clinic;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Livewire\Livewire;

it('allows platform owners to view the plans list', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    Plan::factory()->create(['name' => 'Professional']);

    $this->actingAs($owner)
        ->get(route('owner.plans.index'))
        ->assertOk()
        ->assertSee('Professional')
        ->assertSee(__('Billing Interval'));
});

it('lists, searches, and sorts plans in the plans table', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    Plan::factory()->create([
        'name' => 'Professional',
        'slug' => 'professional',
        'price' => '299.00',
        'billing_interval' => 'yearly',
    ]);
    Plan::factory()->create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => '99.00',
        'billing_interval' => 'monthly',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.plans.index')
        ->assertSee('Professional')
        ->assertSee('Starter')
        ->set('search', 'professional')
        ->assertSee('Professional')
        ->assertDontSee('Starter')
        ->set('search', '')
        ->call('sort', 'price')
        ->assertSet('sortBy', 'price')
        ->assertSet('sortDirection', 'asc');
});

it('allows platform owners to create a plan', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);

    Livewire::actingAs($owner)
        ->test('pages::owner.plans.index')
        ->set('name', 'Enterprise')
        ->set('price', '499.00')
        ->set('billingInterval', 'yearly')
        ->set('trialDays', 30)
        ->set('isActive', true)
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close');

    $this->assertDatabaseHas('plans', [
        'name' => 'Enterprise',
        'slug' => 'enterprise',
        'price' => '499.00',
        'billing_interval' => 'yearly',
        'trial_days' => 30,
        'is_active' => true,
    ]);
});

it('requires all plan fields and rejects duplicate slugs', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    Plan::factory()->create(['slug' => 'enterprise']);

    Livewire::actingAs($owner)
        ->test('pages::owner.plans.index')
        ->call('savePlan')
        ->assertHasErrors([
            'name' => 'required',
            'slug' => 'required',
            'price' => 'required',
        ])
        ->set('name', 'Enterprise')
        ->set('slug', 'enterprise')
        ->set('price', '0')
        ->set('billingInterval', 'monthly')
        ->set('trialDays', 14)
        ->set('isActive', true)
        ->call('savePlan')
        ->assertHasErrors(['slug' => 'unique']);
});

it('allows platform owners to update a plan without conflicting with its own slug', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $plan = Plan::factory()->create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 100,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.plans.index')
        ->call('editPlan', $plan->id)
        ->set('name', 'Starter Plus')
        ->set('price', '150.00')
        ->call('savePlan')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('plans', [
        'id' => $plan->id,
        'name' => 'Starter Plus',
        'slug' => 'starter',
        'price' => '150.00',
    ]);
});

it('prevents deleting plans that have subscriptions', function () {
    $owner = User::factory()->create(['is_platform_owner' => true]);
    $clinic = Clinic::factory()->create();
    $plan = Plan::factory()->create();

    Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::owner.plans.index')
        ->call('deletePlan', $plan->id)
        ->assertDispatched('toast-show');

    $this->assertDatabaseHas('plans', ['id' => $plan->id]);
});
