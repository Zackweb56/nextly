<?php

use App\Models\User;
use Database\Seeders\PlatformOwnerSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config([
        'platform-owner.name' => 'Platform Owner',
        'platform-owner.email' => 'owner@example.com',
        'platform-owner.password' => 'a-secure-password-123',
    ]);
});

it('creates the configured platform owner and can be rerun safely', function () {
    $this->seed(PlatformOwnerSeeder::class);
    $this->seed(PlatformOwnerSeeder::class);

    $owner = User::query()->where('email', 'owner@example.com')->sole();

    expect($owner->name)->toBe('Platform Owner')
        ->and($owner->is_platform_owner)->toBeTrue()
        ->and(Hash::check('a-secure-password-123', $owner->password))->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});

it('promotes an existing configured account without changing its password', function () {
    $user = User::factory()->create([
        'name' => 'Existing User',
        'email' => 'owner@example.com',
        'password' => 'original-password',
        'is_platform_owner' => false,
    ]);

    $this->seed(PlatformOwnerSeeder::class);

    $user->refresh();

    expect($user->name)->toBe('Existing User')
        ->and($user->is_platform_owner)->toBeTrue()
        ->and(Hash::check('original-password', $user->password))->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});
