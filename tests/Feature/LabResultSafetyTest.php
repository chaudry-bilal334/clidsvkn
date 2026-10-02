<?php

use App\Models\AuditLog;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\User;
use App\Services\LabVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function lab_recorder(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function lab_verifier(): User
{
    return User::factory()->create(['role' => 'Pharmacist']);
}

function lab_order(?Patient $patient = null): LabOrder
{
    return LabOrder::factory()->create([
        'patient_id' => ($patient ?? Patient::factory()->create())->id,
        'test_name' => 'Complete Blood Count',
        'status' => LabOrder::STATUS_PENDING,
        'result' => null,
        'verification_state' => LabOrder::VERIFICATION_PENDING,
    ]);
}

/**
 * An order whose sample has been collected but which has no result yet — the
 * state every result-recording test must start from now that result entry is
 * fail-closed on sample collection.
 */
function lab_collected_order(?Patient $patient = null): LabOrder
{
    $order = lab_order($patient);
    app(LabVerificationService::class)->collectSample($order, lab_recorder(), null);

    return $order->refresh();
}

/**
 * Take an order all the way to verified, the only state that may reach a
 * patient.
 */
function lab_verified_order(?Patient $patient = null): LabOrder
{
    $service = app(LabVerificationService::class);

    $order = lab_collected_order($patient);
    $service->recordResult($order, lab_recorder(), 'WBC 11.2', LabOrder::STATUS_COMPLETED);
    $service->verify($order->refresh(), lab_verifier());

    return $order->refresh();
}

test('completing an order without a result is rejected', function () {
    $actor = lab_recorder();
    $order = lab_order();

    $this->actingAs($actor)
        ->putJson("/api/v1/lab-orders/{$order->id}", ['status' => 'completed'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.result.0', 'A result is required before the order can be completed.');

    expect($order->refresh()->status)->toBe(LabOrder::STATUS_PENDING)
        ->and($order->result)->toBeNull();
});

test('a verified result cannot be silently overwritten through the update endpoint', function () {
    $order = lab_verified_order();
    $actor = lab_recorder();

    $this->actingAs($actor)
        ->putJson("/api/v1/lab-orders/{$order->id}", [
            'status' => 'completed',
            'result' => 'WBC 99.9',
        ])
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.result.0',
            'Use POST /lab-orders/'.$order->id.'/amend with a mandatory reason.',
        );

    expect($order->refresh()->result)->toBe('WBC 11.2')
        ->and($order->verification_state)->toBe(LabOrder::VERIFICATION_VERIFIED);
});

test('a status-only change on a verified result is still audited', function () {
    $order = lab_verified_order();
    $actor = lab_recorder();

    $this->actingAs($actor)
        ->putJson("/api/v1/lab-orders/{$order->id}", ['status' => 'cancelled'])
        ->assertOk();

    expect($order->refresh()->status)->toBe(LabOrder::STATUS_CANCELLED)
        ->and(AuditLog::query()->where('action', 'lab.status_changed')->count())->toBe(1);
});

test('amending a result clears the verifier and withdraws it from the patient until re-verified', function () {
    $order = lab_verified_order();

    $this->actingAs(lab_recorder())
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/lab-orders/{$order->id}/amend", [
            'reason' => 'Haemolysed sample',
            'result' => 'WBC 8.4',
        ])
        ->assertOk()
        ->assertJsonPath('data.verification_state', LabOrder::VERIFICATION_AMENDED)
        ->assertJsonPath('data.verifier_id', null)
        ->assertJsonPath('data.patient_releasable', false);

    $order->refresh();

    // The amendment needs a fresh second pair of eyes before it counts.
    expect($order->result)->toBe('WBC 8.4')
        ->and($order->verified_at)->toBeNull()
        ->and($order->verification_state)->toBe(LabOrder::VERIFICATION_AMENDED)
        ->and(AuditLog::query()->where('action', 'lab.amended')->count())->toBe(1);
});

test('an amendment cannot be re-verified by the person who made it', function () {
    $order = lab_verified_order();
    $amender = lab_recorder();

    app(LabVerificationService::class)->amend($order, $amender, 'Sample error', 'WBC 8.4');

    $this->actingAs($amender)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/lab-orders/{$order->id}/verify")
        ->assertUnprocessable();

    // A genuinely different verifier can put it back to verified.
    $this->actingAs(lab_verifier())
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/lab-orders/{$order->id}/verify")
        ->assertOk()
        ->assertJsonPath('data.verification_state', LabOrder::VERIFICATION_VERIFIED)
        ->assertJsonPath('data.patient_releasable', true);
});

test('the recorder cannot verify their own result', function () {
    $order = lab_collected_order();
    $recorder = lab_recorder();

    app(LabVerificationService::class)->recordResult($order, $recorder, 'WBC 11.2', LabOrder::STATUS_COMPLETED);

    $this->actingAs($recorder)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/lab-orders/{$order->id}/verify")
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.verifier_id.0',
            'The result must be verified by a different user than the one who recorded it.',
        );

    expect($order->refresh()->verification_state)->toBe(LabOrder::VERIFICATION_PENDING);
});

test('an already verified result cannot be verified a second time', function () {
    $order = lab_verified_order();

    $this->actingAs(lab_verifier())
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/lab-orders/{$order->id}/verify")
        ->assertUnprocessable()
        ->assertJsonPath('errors.lab_order.0', 'This result has already been verified.');
});

test('the patient portal only exposes verified results', function () {
    $patient = Patient::factory()->create();
    $patientUser = User::factory()->create(['role' => 'Patient', 'patient_id' => $patient->id]);

    // One verified, one still awaiting verification, one verified but with no
    // result value at all.
    $verified = lab_verified_order($patient);
    $pending = lab_collected_order($patient);
    $service = app(LabVerificationService::class);
    $service->recordResult($pending, lab_recorder(), 'WBC 11.2', LabOrder::STATUS_COMPLETED);

    $empty = lab_collected_order($patient);
    $service->recordResult($empty, lab_recorder(), 'WBC 7.1', LabOrder::STATUS_COMPLETED);
    $service->verify($empty->refresh(), lab_verifier());
    $empty->update(['result' => null]);

    $response = $this->actingAs($patientUser)
        ->getJson('/api/v1/portal/lab-results')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids->all())->toBe([$verified->id])
        ->and($ids->contains($pending->id))->toBeFalse()
        ->and($ids->contains($empty->id))->toBeFalse();
});

test('an amended result disappears from the portal until it is re-verified', function () {
    $patient = Patient::factory()->create();
    $patientUser = User::factory()->create(['role' => 'Patient', 'patient_id' => $patient->id]);

    $order = lab_verified_order($patient);

    $this->actingAs($patientUser)
        ->getJson('/api/v1/portal/lab-results')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    app(LabVerificationService::class)->amend($order, lab_recorder(), 'Recalculated', 'WBC 8.4');

    // The patient must not see the amended value while it is unverified.
    $this->actingAs($patientUser)
        ->getJson('/api/v1/portal/lab-results')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $service = app(LabVerificationService::class);
    $service->verify($order->refresh(), lab_verifier());

    $this->actingAs($patientUser)
        ->getJson('/api/v1/portal/lab-results')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.result', 'WBC 8.4');
});

test('a portal lab list never leaks another patient result', function () {
    $patient = Patient::factory()->create();
    $patientUser = User::factory()->create(['role' => 'Patient', 'patient_id' => $patient->id]);

    lab_verified_order($patient);
    lab_verified_order(Patient::factory()->create());

    $this->actingAs($patientUser)
        ->getJson('/api/v1/portal/lab-results')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('only a verified result can be finalized, and finalizing is audited', function () {
    $verifier = lab_verifier();
    $service = app(LabVerificationService::class);

    $pending = lab_collected_order();
    $service->recordResult($pending, lab_recorder(), 'WBC 11.2', LabOrder::STATUS_COMPLETED);

    $this->actingAs($verifier)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/lab-orders/{$pending->id}/finalize")
        ->assertUnprocessable()
        ->assertJsonPath('errors.lab_order.0', 'Only a verified result can be finalized.');

    $verified = lab_verified_order();

    $this->actingAs($verifier)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/lab-orders/{$verified->id}/finalize")
        ->assertOk()
        ->assertJsonPath('data.is_finalized', true);

    expect($verified->refresh()->finalized_by)->toBe($verifier->id)
        ->and(AuditLog::query()->where('action', 'lab.finalized')->count())->toBe(1);

    // Finalizing twice is rejected rather than silently re-stamped.
    $this->actingAs($verifier)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/lab-orders/{$verified->id}/finalize")
        ->assertUnprocessable()
        ->assertJsonPath('errors.lab_order.0', 'This result is already finalized.');
});
