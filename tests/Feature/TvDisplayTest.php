<?php

use App\Models\Clinic;
use App\Models\DisplayToken;
use App\Models\TvPairing;

it('can create a short-lived tv pairing code', function () {
    $pairing = TvPairing::create([
        'pairing_code' => 'A8F2K9',
        'expires_at' => now()->addMinutes(10),
    ]);

    expect($pairing->pairing_code)->toBe('A8F2K9');
    expect($pairing->paired_at)->toBeNull();
    expect($pairing->expires_at->isFuture())->toBeTrue();
});

it('can attach a display token to a clinic', function () {
    $clinic = Clinic::factory()->create();

    $token = DisplayToken::factory()->create([
        'clinic_id' => $clinic->id,
        'label' => 'Consultation Room TV',
    ]);

    expect($clinic->displayTokens)->toHaveCount(1);
    expect($clinic->displayTokens->first()->label)->toBe('Consultation Room TV');

    // Ensure the token is a hash (64 characters), not a raw string
    expect(strlen($token->token_hash))->toBe(64);
});
