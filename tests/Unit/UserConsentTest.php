<?php

use App\Models\User;

test('users consent to all channels by default', function () {
    $user = new User(['notification_preferences' => null]);

    expect($user->consentsTo(User::CONSENT_APPOINTMENTS))->toBeTrue()
        ->and($user->consentsTo(User::CONSENT_LOW_STOCK))->toBeTrue()
        ->and($user->consentsTo(User::CONSENT_SECURITY))->toBeTrue();
});

test('users can opt out of individual channels', function () {
    $user = new User(['notification_preferences' => ['security' => false]]);

    expect($user->consentsTo(User::CONSENT_SECURITY))->toBeFalse()
        ->and($user->consentsTo(User::CONSENT_APPOINTMENTS))->toBeTrue()
        ->and($user->consentsTo(User::CONSENT_LOW_STOCK))->toBeTrue();
});
