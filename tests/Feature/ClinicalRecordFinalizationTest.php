<?php

use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function crf_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function crf_receptionist(): User
{
    return User::factory()->create(['role' => 'Receptionist']);
}

/**
 * Fresh re-auth marker stamped into the test session, matching what the
 * reauth endpoint writes for a just-confirmed password/TOTP challenge.
 *
 * @return array<string, int>
 */
function crf_reauth(): array
{
    return ['reauth_verified_at' => now()->timestamp];
}

test('finalizing an encounter locks it against further vitals and diagnoses', function () {
    $doctor = crf_doctor();
    $encounter = Encounter::factory()->create(['doctor_id' => $doctor->id]);

    $this->actingAs($doctor)->withSession(crf_reauth())
        ->postJson("/api/v1/encounters/{$encounter->id}/finalize")
        ->assertOk()
        ->assertJsonPath('data.status', Encounter::STATUS_FINALIZED)
        ->assertJsonPath('data.finalized_by', $doctor->id);

    $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$encounter->id}/vitals", ['blood_pressure' => '120/80'])
        ->assertUnprocessable();

    $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$encounter->id}/diagnoses", ['icd_code' => 'J06.9'])
        ->assertUnprocessable();

    expect($encounter->fresh()->status)->toBe(Encounter::STATUS_FINALIZED)
        ->and($encounter->fresh()->vitals()->count())->toBe(0)
        ->and($encounter->fresh()->diagnoses()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'encounter.finalized')->count())->toBe(1);
});

test('a draft encounter can only be finalized once', function () {
    $doctor = crf_doctor();
    $encounter = Encounter::factory()->create(['doctor_id' => $doctor->id]);

    $this->actingAs($doctor)->withSession(crf_reauth())
        ->postJson("/api/v1/encounters/{$encounter->id}/finalize")
        ->assertOk();

    $this->actingAs($doctor)->withSession(crf_reauth())
        ->postJson("/api/v1/encounters/{$encounter->id}/finalize")
        ->assertUnprocessable()
        ->assertJsonPath('errors.encounter.0', 'Only a draft encounter can be finalized.');
});

test('voiding an encounter requires a reason, preserves the original and audits the actor', function () {
    $doctor = crf_doctor();
    $encounter = Encounter::factory()->create(['doctor_id' => $doctor->id]);
    $originalCreatedAt = $encounter->created_at;

    $this->actingAs($doctor)->withSession(crf_reauth())->postJson("/api/v1/encounters/{$encounter->id}/finalize")->assertOk();

    $this->actingAs($doctor)->withSession(crf_reauth())
        ->postJson("/api/v1/encounters/{$encounter->id}/void", [])
        ->assertUnprocessable();

    $this->actingAs($doctor)->withSession(crf_reauth())
        ->postJson("/api/v1/encounters/{$encounter->id}/void", ['reason' => 'Documented in error.'])
        ->assertOk()
        ->assertJsonPath('data.status', Encounter::STATUS_VOIDED)
        ->assertJsonPath('data.void_reason', 'Documented in error.');

    $voided = $encounter->fresh();

    expect($voided->voided_by)->toBe($doctor->id)
        ->and($voided->voided_at)->not->toBeNull()
        ->and($voided->created_at->equalTo($originalCreatedAt))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'encounter.voided')->count())->toBe(1);
});

test('amending a finalized encounter creates a linked draft without mutating the original', function () {
    $doctor = crf_doctor();
    $encounter = Encounter::factory()->create(['doctor_id' => $doctor->id]);

    $this->actingAs($doctor)->withSession(crf_reauth())->postJson("/api/v1/encounters/{$encounter->id}/finalize")->assertOk();

    $amendmentId = $this->actingAs($doctor)->withSession(crf_reauth())
        ->postJson("/api/v1/encounters/{$encounter->id}/amend")
        ->assertCreated()
        ->assertJsonPath('data.status', Encounter::STATUS_DRAFT)
        ->assertJsonPath('data.amends_encounter_id', $encounter->id)
        ->json('data.id');

    expect($encounter->fresh()->status)->toBe(Encounter::STATUS_FINALIZED)
        ->and($encounter->fresh()->vitals()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'encounter.amended')->count())->toBe(1);

    $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$amendmentId}/vitals", ['heart_rate' => 80])
        ->assertCreated();
});

test('a finalized prescription is signed and cannot be finalized twice', function () {
    $doctor = crf_doctor();
    $prescription = Prescription::factory()->create(['status' => 'pending', 'doctor_id' => $doctor->id]);

    $this->actingAs($doctor)->withSession(crf_reauth())
        ->postJson("/api/v1/prescriptions/{$prescription->id}/finalize")
        ->assertOk()
        ->assertJsonPath('data.status', Prescription::STATUS_FINALIZED)
        ->assertJsonPath('data.finalized_by', $doctor->id);

    $this->actingAs($doctor)->withSession(crf_reauth())
        ->postJson("/api/v1/prescriptions/{$prescription->id}/finalize")
        ->assertUnprocessable()
        ->assertJsonPath('errors.prescription.0', 'Only a pending prescription can be finalized.');

    expect(AuditLog::query()->where('action', 'prescription.finalized')->count())->toBe(1);
});

test('voiding a prescription requires a reason and a dispensed prescription cannot be voided', function () {
    $doctor = crf_doctor();
    $pending = Prescription::factory()->create(['status' => 'pending', 'doctor_id' => $doctor->id]);
    $dispensed = Prescription::factory()->create(['status' => 'dispensed', 'doctor_id' => $doctor->id]);

    $this->actingAs($doctor)->withSession(crf_reauth())
        ->postJson("/api/v1/prescriptions/{$pending->id}/void", [])
        ->assertUnprocessable();

    $this->actingAs($doctor)->withSession(crf_reauth())
        ->postJson("/api/v1/prescriptions/{$pending->id}/void", ['reason' => 'Wrong medicine selected.'])
        ->assertOk()
        ->assertJsonPath('data.status', Prescription::STATUS_VOIDED)
        ->assertJsonPath('data.void_reason', 'Wrong medicine selected.');

    $this->actingAs($doctor)->withSession(crf_reauth())
        ->postJson("/api/v1/prescriptions/{$dispensed->id}/void", ['reason' => 'Too late.'])
        ->assertUnprocessable();

    expect($pending->fresh()->voided_by)->toBe($doctor->id)
        ->and($dispensed->fresh()->status)->toBe('dispensed')
        ->and(AuditLog::query()->where('action', 'prescription.voided')->count())->toBe(1);
});

test('amending a finalized prescription copies its items and links to the original', function () {
    $doctor = crf_doctor();
    $prescription = Prescription::factory()->create(['status' => 'finalized', 'doctor_id' => $doctor->id]);
    PrescriptionItem::factory()->count(2)->create(['prescription_id' => $prescription->id]);

    $amendmentId = $this->actingAs($doctor)->withSession(crf_reauth())
        ->postJson("/api/v1/prescriptions/{$prescription->id}/amend")
        ->assertCreated()
        ->assertJsonPath('data.amends_prescription_id', $prescription->id)
        ->assertJsonPath('data.status', 'pending')
        ->json('data.id');

    $amendment = Prescription::with('items')->findOrFail($amendmentId);

    expect($prescription->fresh()->status)->toBe(Prescription::STATUS_FINALIZED)
        ->and($prescription->fresh()->items()->count())->toBe(2)
        ->and($amendment->items->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'prescription.amended')->count())->toBe(1);
});

test('a finalized prescription can still be dispensed while keeping its locked status', function () {
    $doctor = crf_doctor();
    $prescription = Prescription::factory()->create(['status' => 'pending', 'doctor_id' => $doctor->id]);

    $item = InventoryItem::factory()->create(['item_name' => 'Amoxicillin 500mg Capsules', 'stock_quantity' => 0, 'reorder_level' => 1]);
    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'initial_quantity' => 10,
        'current_quantity' => 10,
    ]);

    $this->actingAs($doctor)->withSession(crf_reauth())->postJson("/api/v1/prescriptions/{$prescription->id}/finalize")->assertOk();

    $pharmacist = User::factory()->create(['role' => 'Pharmacist']);

    $this->actingAs($pharmacist)
        ->postJson('/api/v1/dispensings', [
            'prescription_id' => $prescription->id,
            'items' => [['medicine_name' => 'Amoxicillin 500mg Capsules', 'quantity' => 4]],
        ])
        ->assertCreated();

    expect($prescription->fresh()->status)->toBe(Prescription::STATUS_FINALIZED)
        ->and((float) $item->fresh()->stock_quantity)->toBe(6.0);
});

test('clinical finalization is authorized and requires authentication', function () {
    $this->postJson('/api/v1/encounters/1/finalize')->assertUnauthorized();
    $this->postJson('/api/v1/prescriptions/1/void', ['reason' => 'x'])->assertUnauthorized();

    $receptionist = crf_receptionist();
    $encounter = Encounter::factory()->create();

    $this->actingAs($receptionist)
        ->postJson("/api/v1/encounters/{$encounter->id}/finalize")
        ->assertForbidden();
});
