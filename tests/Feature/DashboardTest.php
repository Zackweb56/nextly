<?php

use App\Models\User;

test('the home page redirects guests to login', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'));
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('platform owners visiting the clinic dashboard are redirected to the owner dashboard', function () {
    $this->actingAs(User::factory()->create(['is_platform_owner' => true]))
        ->get(route('dashboard'))
        ->assertRedirect(route('owner.dashboard'));
});
