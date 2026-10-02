<?php

use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Authorize the currently authenticated user for each private channel the
| clinic SPA subscribes to. Rejecting a subscription (returning false) or a
| missing consent preference triggers a 403 on the /broadcasting/auth
| endpoint. Role channels are additionally gated on the user's real-time
| notification consent so opted-out staff never receive broadcasts.
|
*/

Broadcast::channel('clinic.users.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

Broadcast::channel('clinic.telehealth.{sessionId}', function (User $user, int $sessionId) {
    if (Str::lower($user->role) === 'admin') {
        return true;
    }

    $session = TelehealthSession::find($sessionId);

    if (! $session) {
        return false;
    }

    if ((int) $session->doctor_id === (int) $user->id) {
        return true;
    }

    return $user->patient_id !== null && (int) $session->patient_id === (int) $user->patient_id;
});

Broadcast::channel('clinic.roles.doctor', function (User $user) {
    return Str::lower($user->role) === 'doctor'
        && $user->consentsTo(User::CONSENT_APPOINTMENTS);
});

Broadcast::channel('clinic.roles.receptionist', function (User $user) {
    return Str::lower($user->role) === 'receptionist'
        && $user->consentsTo(User::CONSENT_APPOINTMENTS);
});

Broadcast::channel('clinic.roles.pharmacist', function (User $user) {
    return Str::lower($user->role) === 'pharmacist'
        && $user->consentsTo(User::CONSENT_LOW_STOCK);
});

Broadcast::channel('clinic.roles.admin', function (User $user) {
    return Str::lower($user->role) === 'admin'
        && $user->consentsTo(User::CONSENT_LOW_STOCK)
        && $user->consentsTo(User::CONSENT_SECURITY);
});

Broadcast::channel('clinic.roles.accountant', function (User $user) {
    return Str::lower($user->role) === 'accountant';
});
