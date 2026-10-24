<?php

use App\Models\Clinic;
use App\Models\Subscription;
use App\Models\User;

test('platform owners can visit the owner dashboard', function () {
    $user = User::factory()->create(['is_platform_owner' => true]);

    $this->actingAs($user)
        ->get(route('owner.dashboard'))
        ->assertOk();
});

test('clinic staff cannot visit the owner dashboard', function () {
    $clinic = Clinic::factory()->create();
    $user = User::factory()->create(['is_platform_owner' => false]);
    $user->clinics()->attach($clinic->id, [
        'role' => 'clinic_staff',
        'is_active' => true,
    ]);

    Subscription::factory()->create([
        'clinic_id' => $clinic->id,
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->get(route('owner.dashboard'))
        ->assertForbidden();
});

test('guests are redirected to the login page from the owner dashboard', function () {
    $this->get(route('owner.dashboard'))
        ->assertRedirect(route('login'));
});
