<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\User;
use App\Services\LabVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

pest()->use(RefreshDatabase::class);

function lsc_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function lsc_pharmacist(): User
{
    return User::factory()->create(['role' => 'Pharmacist']);
}

function lsc_order(): LabOrder
{
    return LabOrder::factory()->create([
        'patient_id' => Patient::factory()->create()->id,
        'test_name' => 'Complete Blood Count',
        'status' => LabOrder::STATUS_PENDING,
        'result' => null,
        'verification_state' => LabOrder::VERIFICATION_PENDING,
    ]);
}

test('collectSample transitions an ordered order to sample_collected and stamps actor and time', function () {
    $actor = lsc_doctor();
    $order = lsc_order();

    $collected = app(LabVerificationService::class)->collectSample($order, $actor, 'SAMPLE-001');

    expect($collected->status)->toBe(LabOrder::STATUS_SAMPLE_COLLECTED)
        ->and($collected->sample_collected_at)->not->toBeNull()
        ->and($collected->sample_collected_by)->toBe($actor->id)
        ->and($collected->sample_identifier)->toBe('SAMPLE-001');

    expect(AuditLog::query()->where('action', 'lab.sample_collected')->count())->toBe(1);
});

test('collectSample without an identifier stores null and stays valid', function () {
    $actor = lsc_doctor();
    $order = lsc_order();

    $collected = app(LabVerificationService::class)->collectSample($order, $actor, null);

    expect($collected->status)->toBe(LabOrder::STATUS_SAMPLE_COLLECTED)
        ->and($collected->sample_identifier)->toBeNull();
});

test('collectSample treats a blank identifier as null', function () {
    $actor = lsc_doctor();
    $order = lsc_order();

    $collected = app(LabVerificationService::class)->collectSample($order, $actor, '   ');

    expect($collected->sample_identifier)->toBeNull();
});

test('collectSample on an already-collected order is rejected', function () {
    $actor = lsc_doctor();
    $order = lsc_order();
    app(LabVerificationService::class)->collectSample($order, $actor, 'SAMPLE-001');

    app(LabVerificationService::class)->collectSample($order->refresh(), lsc_doctor(), 'SAMPLE-002');
})->throws(ValidationException::class, 'This sample has already been collected.');

test('collectSample on a non-pending order is rejected', function () {
    $actor = lsc_doctor();
    $order = lsc_order();
    $order->update(['status' => LabOrder::STATUS_COMPLETED]);

    app(LabVerificationService::class)->collectSample($order->refresh(), $actor, 'SAMPLE-001');
})->throws(ValidationException::class, 'Only an ordered lab order can have its sample collected.');

test('a duplicate sample_identifier in the same branch is rejected', function () {
    $actor = lsc_doctor();
    $order = lsc_order();
    app(LabVerificationService::class)->collectSample($order, $actor, 'SAMPLE-DUP');

    $second = lsc_order();
    app(LabVerificationService::class)->collectSample($second, lsc_doctor(), 'SAMPLE-DUP');
})->throws(ValidationException::class, 'This sample identifier is already in use in this branch.');

test('the same sample_identifier is allowed in a different branch', function () {
    $actor = lsc_doctor();
    $order = lsc_order();
    app(LabVerificationService::class)->collectSample($order, $actor, 'SAMPLE-X');

    $second = lsc_order();
    $second->update(['branch_id' => Branch::factory()->create()->id]);

    $collected = app(LabVerificationService::class)->collectSample($second, lsc_doctor(), 'SAMPLE-X');

    expect($collected->sample_identifier)->toBe('SAMPLE-X');
});

test('recordResult is rejected until the sample is collected (fail-closed)', function () {
    $actor = lsc_doctor();
    $order = lsc_order();

    app(LabVerificationService::class)->recordResult($order, $actor, 'WBC 11.2', LabOrder::STATUS_COMPLETED);
})->throws(ValidationException::class, 'A sample must be collected before a result can be recorded.');

test('recordResult succeeds once the sample is collected', function () {
    $actor = lsc_doctor();
    $order = lsc_order();
    app(LabVerificationService::class)->collectSample($order, $actor, null);

    $recorded = app(LabVerificationService::class)->recordResult($order->refresh(), $actor, 'WBC 11.2', LabOrder::STATUS_COMPLETED);

    expect($recorded->result)->toBe('WBC 11.2')
        ->and($recorded->status)->toBe(LabOrder::STATUS_COMPLETED);
});

test('the collect-sample endpoint requires authentication', function () {
    $order = lsc_order();

    $this->postJson("/api/v1/lab-orders/{$order->id}/collect-sample")
        ->assertUnauthorized();
});

test('the collect-sample endpoint requires the prescriptions.manage permission', function () {
    $order = lsc_order();

    $this->actingAs(lsc_pharmacist())
        ->postJson("/api/v1/lab-orders/{$order->id}/collect-sample")
        ->assertForbidden();
});

test('the collect-sample endpoint collects a sample and returns the new state', function () {
    $actor = lsc_doctor();
    $order = lsc_order();

    $this->actingAs($actor)
        ->postJson("/api/v1/lab-orders/{$order->id}/collect-sample", ['sample_identifier' => 'SAMPLE-100'])
        ->assertOk()
        ->assertJsonPath('data.status', LabOrder::STATUS_SAMPLE_COLLECTED)
        ->assertJsonPath('data.sample_identifier', 'SAMPLE-100')
        ->assertJsonPath('data.sample_collected_by', $actor->id);
});

test('the collect-sample endpoint returns a readable 422 for a duplicate identifier', function () {
    $actor = lsc_doctor();
    $order = lsc_order();
    app(LabVerificationService::class)->collectSample($order, $actor, 'SAMPLE-DUP');

    $second = lsc_order();

    $this->actingAs(lsc_doctor())
        ->postJson("/api/v1/lab-orders/{$second->id}/collect-sample", ['sample_identifier' => 'SAMPLE-DUP'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.sample_identifier.0', 'This sample identifier is already in use in this branch.');
});

test('the collect-sample endpoint rejects a second collection with 422', function () {
    $actor = lsc_doctor();
    $order = lsc_order();
    app(LabVerificationService::class)->collectSample($order, $actor, null);

    $this->actingAs(lsc_doctor())
        ->postJson("/api/v1/lab-orders/{$order->id}/collect-sample")
        ->assertUnprocessable()
        ->assertJsonPath('errors.lab_order.0', 'This sample has already been collected.');
});

test('recording a result through the update endpoint is rejected before sample collection', function () {
    $actor = lsc_doctor();
    $order = lsc_order();

    $this->actingAs($actor)
        ->putJson("/api/v1/lab-orders/{$order->id}", ['result' => 'WBC 11.2', 'status' => 'completed'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.result.0', 'A sample must be collected before a result can be recorded.');
});

test('a full lab order flows sample collection through result, verify, and finalize', function () {
    $doctor = lsc_doctor();
    $pharmacist = lsc_pharmacist();
    $order = lsc_order();
    $service = app(LabVerificationService::class);

    $service->collectSample($order, $doctor, 'SAMPLE-FULL');
    expect($order->refresh()->status)->toBe(LabOrder::STATUS_SAMPLE_COLLECTED);

    $service->recordResult($order->refresh(), $doctor, 'Hb 13.2 g/dL', LabOrder::STATUS_COMPLETED);
    expect($order->refresh()->verification_state)->toBe(LabOrder::VERIFICATION_PENDING);

    $service->verify($order->refresh(), $pharmacist);
    expect($order->refresh()->verification_state)->toBe(LabOrder::VERIFICATION_VERIFIED);

    $this->actingAs($pharmacist)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/lab-orders/{$order->id}/finalize")
        ->assertOk()
        ->assertJsonPath('data.is_finalized', true);

    expect($order->refresh()->finalized_at)->not->toBeNull();
});

test('a sample-collected but unresulted order never appears in the patient portal', function () {
    $patient = Patient::factory()->create();
    $patientUser = User::factory()->create(['role' => 'Patient', 'patient_id' => $patient->id]);

    $collected = lsc_order();
    $collected->update(['patient_id' => $patient->id]);
    app(LabVerificationService::class)->collectSample($collected, lsc_doctor(), 'SAMPLE-PORTAL');

    $response = $this->actingAs($patientUser)
        ->getJson('/api/v1/portal/lab-results')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->not->toContain($collected->id);
});
