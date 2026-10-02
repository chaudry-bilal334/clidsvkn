<?php

use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

pest()->use(RefreshDatabase::class);

function sr_user(string $role): User
{
    return User::factory()->create(['role' => $role]);
}

/**
 * Create a user with an active TOTP setup (secret confirmed) plus recovery
 * codes, mirroring the state a completed 2fa onboarding leaves behind.
 *
 * @return array{0: User, 1: string}
 */
function sr_user_with_2fa(): array
{
    $admin = User::factory()->create(['role' => 'Admin']);

    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey();

    $admin->two_factor_secret = $secret;
    $admin->two_factor_confirmed_at = now();
    $admin->save();

    app(TwoFactorAuthService::class)->issueRecoveryCodes($admin);

    return [$admin, $secret];
}

dataset('sensitive guarded transitions', function (): array {
    return [
        'encounter finalize' => [
            'Doctor',
            fn (User $user): string => '/api/v1/encounters/'.Encounter::factory()->create(['doctor_id' => $user->id])->id.'/finalize',
        ],
        'encounter void' => [
            'Doctor',
            fn (User $user): string => '/api/v1/encounters/'.Encounter::factory()->create(['doctor_id' => $user->id])->id.'/void',
        ],
        'encounter amend' => [
            'Doctor',
            fn (User $user): string => '/api/v1/encounters/'.Encounter::factory()->create(['doctor_id' => $user->id])->id.'/amend',
        ],
        'prescription finalize' => [
            'Doctor',
            fn (): string => '/api/v1/prescriptions/'.Prescription::factory()->create(['status' => 'pending'])->id.'/finalize',
        ],
        'prescription void' => [
            'Doctor',
            fn (): string => '/api/v1/prescriptions/'.Prescription::factory()->create(['status' => 'pending'])->id.'/void',
        ],
        'prescription amend' => [
            'Doctor',
            fn (): string => '/api/v1/prescriptions/'.Prescription::factory()->create(['status' => 'finalized'])->id.'/amend',
        ],
        'lab verify' => [
            'Pharmacist',
            fn (User $user): string => '/api/v1/lab-orders/'.LabOrder::factory()->create([
                'status' => 'completed',
                'recorded_by' => $user->id,
                'verification_state' => 'pending_verification',
            ])->id.'/verify',
        ],
        'lab amend' => [
            'Pharmacist',
            fn (User $user): string => '/api/v1/lab-orders/'.LabOrder::factory()->create([
                'status' => 'completed',
                'verification_state' => 'verified',
                'verifier_id' => $user->id,
            ])->id.'/amend',
        ],
        'invoice finalize' => [
            'Accountant',
            fn (): string => '/api/v1/invoices/'.Invoice::factory()->create(['status' => 'generated'])->id.'/finalize',
        ],
        'invoice refund' => [
            'Accountant',
            fn (): string => '/api/v1/invoices/'.Invoice::factory()->paid()->create()->id.'/refunds',
        ],
    ];
});

test('each sensitive transition is rejected with 403 and reauth_required when no fresh re-auth exists', function (string $role, Closure $endpoint) {
    $user = sr_user($role);

    $this->actingAs($user)
        ->postJson($endpoint($user))
        ->assertStatus(403)
        ->assertJson([
            'success' => false,
            'reauth_required' => true,
        ]);
})->with('sensitive guarded transitions');

test('an encounter is finalized when the actor re-authenticates first', function () {
    $doctor = sr_user('Doctor');
    $encounter = Encounter::factory()->create(['doctor_id' => $doctor->id]);

    $this->actingAs($doctor)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/encounters/{$encounter->id}/finalize")
        ->assertOk()
        ->assertJsonPath('data.status', Encounter::STATUS_FINALIZED)
        ->assertJsonPath('data.finalized_by', $doctor->id);
});

test('an encounter is voided when the actor re-authenticates first', function () {
    $doctor = sr_user('Doctor');
    $encounter = Encounter::factory()->create(['doctor_id' => $doctor->id]);

    $this->actingAs($doctor)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/encounters/{$encounter->id}/void", ['reason' => 'Documented in error.'])
        ->assertOk()
        ->assertJsonPath('data.status', Encounter::STATUS_VOIDED);
});

test('an encounter is amended into a linked draft when the actor re-authenticates first', function () {
    $doctor = sr_user('Doctor');
    $encounter = Encounter::factory()->create(['doctor_id' => $doctor->id, 'status' => Encounter::STATUS_FINALIZED]);

    $this->actingAs($doctor)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/encounters/{$encounter->id}/amend")
        ->assertCreated()
        ->assertJsonPath('data.amends_encounter_id', $encounter->id)
        ->assertJsonPath('data.status', Encounter::STATUS_DRAFT);
});

test('a prescription is finalized when the actor re-authenticates first', function () {
    $doctor = sr_user('Doctor');
    $prescription = Prescription::factory()->create(['status' => 'pending']);

    $this->actingAs($doctor)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/prescriptions/{$prescription->id}/finalize")
        ->assertOk()
        ->assertJsonPath('data.status', Prescription::STATUS_FINALIZED);
});

test('a prescription is voided when the actor re-authenticates first', function () {
    $doctor = sr_user('Doctor');
    $prescription = Prescription::factory()->create(['status' => 'pending']);

    $this->actingAs($doctor)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/prescriptions/{$prescription->id}/void", ['reason' => 'Wrong medicine selected.'])
        ->assertOk()
        ->assertJsonPath('data.status', Prescription::STATUS_VOIDED);
});

test('a finalized prescription is amended into a linked draft when the actor re-authenticates first', function () {
    $doctor = sr_user('Doctor');
    $prescription = Prescription::factory()->create(['status' => 'finalized']);

    $this->actingAs($doctor)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/prescriptions/{$prescription->id}/amend")
        ->assertCreated()
        ->assertJsonPath('data.amends_prescription_id', $prescription->id)
        ->assertJsonPath('data.status', 'pending');
});

test('a lab result is verified when the actor re-authenticates first', function () {
    $doctor = sr_user('Doctor');
    $pharmacist = sr_user('Pharmacist');
    $labOrder = LabOrder::factory()->create([
        'status' => 'completed',
        'result' => 'Hb 13.2 g/dL',
        'recorded_by' => $doctor->id,
        'verification_state' => 'pending_verification',
    ]);

    $this->actingAs($pharmacist)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/lab-orders/{$labOrder->id}/verify")
        ->assertOk()
        ->assertJsonPath('data.verification_state', LabOrder::VERIFICATION_VERIFIED)
        ->assertJsonPath('data.verifier_id', $pharmacist->id);
});

test('a verified lab result is amended when the actor re-authenticates first', function () {
    $doctor = sr_user('Doctor');
    $pharmacist = sr_user('Pharmacist');
    $labOrder = LabOrder::factory()->create([
        'status' => 'completed',
        'result' => 'Glucose 95 mg/dL',
        'recorded_by' => $doctor->id,
        'verification_state' => LabOrder::VERIFICATION_VERIFIED,
        'verifier_id' => $pharmacist->id,
        'verified_at' => now(),
    ]);

    $this->actingAs($pharmacist)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/lab-orders/{$labOrder->id}/amend", [
            'reason' => 'Repeat sample after re-test.',
            'result' => 'Glucose 110 mg/dL',
        ])
        ->assertOk()
        ->assertJsonPath('data.verification_state', LabOrder::VERIFICATION_AMENDED)
        ->assertJsonPath('data.result', 'Glucose 110 mg/dL');
});

test('an invoice is finalized when the actor re-authenticates first', function () {
    $accountant = sr_user('Accountant');
    $invoice = Invoice::factory()->create(['status' => 'generated']);

    $this->actingAs($accountant)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/invoices/{$invoice->id}/finalize", ['confirmation' => true])
        ->assertOk()
        ->assertJsonPath('data.status', Invoice::STATUS_FINALIZED);
});

test('a payment is refunded when the actor re-authenticates first', function () {
    $accountant = sr_user('Accountant');
    $invoice = Invoice::factory()->paid()->create(['total' => 1000.00, 'subtotal' => 884.96, 'tax' => 115.04]);

    $payment = Payment::create([
        'invoice_id' => $invoice->id,
        'amount' => 1000.00,
        'method' => 'cash',
        'paid_at' => now(),
    ]);

    $this->actingAs($accountant)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'amount' => 250.00,
            'payment_id' => $payment->id,
            'reason' => 'Patient overpaid.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.amount', 250);

    expect((float) $invoice->fresh()->amount_paid)->toBe(750.0);
});

test('an expired re-auth marker is rejected with 403 and reauth_required', function () {
    $doctor = sr_user('Doctor');
    $encounter = Encounter::factory()->create(['doctor_id' => $doctor->id]);

    $this->actingAs($doctor)
        ->withSession(['reauth_verified_at' => now()->subMinutes(10)->timestamp])
        ->postJson("/api/v1/encounters/{$encounter->id}/finalize")
        ->assertStatus(403)
        ->assertJsonPath('reauth_required', true);
});

test('reauth rejects an incorrect password with a 422 validation error', function () {
    $doctor = sr_user('Doctor');

    $this->actingAs($doctor)
        ->postJson('/api/v1/reauth', ['password' => 'not-the-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

test('reauth confirms the current password and unlocks guarded transitions without further setup', function () {
    $doctor = sr_user('Doctor');
    $encounter = Encounter::factory()->create(['doctor_id' => $doctor->id]);

    $this->actingAs($doctor)
        ->postJson('/api/v1/reauth', ['password' => 'password'])
        ->assertOk()
        ->assertSessionHas('reauth_verified_at');

    $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$encounter->id}/finalize")
        ->assertOk()
        ->assertJsonPath('data.status', Encounter::STATUS_FINALIZED);
});

test('reauth rejects an invalid totp code with a 422 validation error', function () {
    [$doctor] = sr_user_with_2fa();

    $this->actingAs($doctor)
        ->postJson('/api/v1/reauth', ['two_factor_code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('two_factor_code');
});

test('reauth confirms a totp code for a two-factor user and stamps the session', function () {
    [$doctor] = sr_user_with_2fa();

    $this->actingAs($doctor)
        ->postJson('/api/v1/reauth', [
            'two_factor_code' => app(Google2FA::class)->getCurrentOtp((string) $doctor->two_factor_secret),
        ])
        ->assertOk()
        ->assertSessionHas('reauth_verified_at');
});

test('reauth status reports whether totp applies to the signed-in user', function () {
    $admin = sr_user_with_2fa()[0];

    $this->actingAs($admin)
        ->getJson('/api/v1/reauth/status')
        ->assertOk()
        ->assertJsonPath('data.two_factor', true)
        ->assertJsonPath('data.password', true);

    $this->actingAs(sr_user('Accountant'))
        ->getJson('/api/v1/reauth/status')
        ->assertOk()
        ->assertJsonPath('data.two_factor', false);
});
