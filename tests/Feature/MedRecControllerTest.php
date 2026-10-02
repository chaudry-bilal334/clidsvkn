<?php

use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\MedicationReconciliation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function mrc_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function mrc_patient(?User $doctor = null): Patient
{
    $doctor ??= mrc_doctor();

    return Patient::factory()->create(['branch_id' => $doctor->branch_id]);
}

function mrc_medrec(User $doctor, Patient $patient, array $overrides = []): MedicationReconciliation
{
    return MedicationReconciliation::factory()->create(array_merge([
        'patient_id' => $patient->id,
        'medicine_name' => 'Metformin',
        'dosage' => '500mg',
        'frequency' => 'BD',
        'reconciled_by' => $doctor->id,
        'is_active' => true,
    ], $overrides));
}

test('medication reconciliations can be listed for a patient', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => Encounter::STATUS_DRAFT,
    ]);

    mrc_medrec($doctor, $patient, ['encounter_id' => $encounter->id, 'medicine_name' => 'Metformin']);
    mrc_medrec($doctor, $patient, ['medicine_name' => 'Atorvastatin', 'is_active' => false]);

    $response = $this->actingAs($doctor)
        ->getJson("/api/v1/patients/{$patient->id}/medication-reconciliations")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0'))->toHaveKeys([
        'id', 'patient_id', 'encounter_id', 'medicine_name', 'dosage',
        'frequency', 'source', 'is_active', 'reconciled_at', 'notes',
    ]);
});

test('medication reconciliation can be created', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/medication-reconciliations", [
            'medicine_name' => 'Metformin',
            'dosage' => '500mg',
            'frequency' => 'BD',
            'source' => 'self_reported',
        ])
        ->assertCreated()
        ->assertJsonPath('data.medicine_name', 'Metformin')
        ->assertJsonPath('data.source', 'self_reported')
        ->assertJsonPath('data.is_active', true);

    expect(MedicationReconciliation::count())->toBe(1);
});

test('medication reconciliation requires the mandatory fields', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);

    // Only the medicine name is mandatory. Dosage and frequency are nullable:
    // patients routinely cannot state them, and demanding them pushes
    // clinicians to type a dose they do not know, which is worse than an
    // explicit null.
    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/medication-reconciliations", [
            'notes' => 'Patient could not state the dose',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['medicine_name'])
        ->assertJsonMissingValidationErrors(['dosage', 'frequency']);
});

test('medication reconciliation accepts an explicitly null or absent dose and frequency', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);

    // This is exactly what the React builder sends when the clinician leaves
    // dosage/frequency/notes blank: null, not an empty string. Before this was
    // allowed the API answered 422 and the frontend swallowed it into a generic
    // "could not be added" toast, so the medication was silently lost.
    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/medication-reconciliations", [
            'medicine_name' => 'Metformin',
            'dosage' => null,
            'frequency' => null,
            'source' => 'self_reported',
            'notes' => null,
        ])
        ->assertCreated()
        ->assertJsonPath('data.dosage', null)
        ->assertJsonPath('data.frequency', null)
        ->assertJsonPath('data.notes', null);

    expect(MedicationReconciliation::where('medicine_name', 'Metformin')->sole()->dosage)->toBeNull();

    // Omitting the keys entirely must behave the same way, not fall through to
    // a NOT NULL violation.
    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/medication-reconciliations", [
            'medicine_name' => 'Aspirin',
        ])
        ->assertCreated()
        ->assertJsonPath('data.dosage', null);

    expect(MedicationReconciliation::where('medicine_name', 'Aspirin')->sole()->dosage)->toBeNull();
});

test('a whitespace-only dose is stored as unknown rather than as a blank column', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);

    // An empty or whitespace-only dose is not a dose. Storing it would leave a row
    // that reads back as "a dose is recorded" while displaying as nothing, and the
    // CDSS dosage check would treat '' as a real zero-milligram dose. Nulling it
    // keeps "unknown" unambiguous -- this is normalisation, not validation failure,
    // because the caller did express an intent to leave the field blank.
    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/medication-reconciliations", [
            'medicine_name' => 'Metformin',
            'dosage' => ' ',
            'frequency' => '',
            'notes' => '  ',
        ])
        ->assertCreated()
        ->assertJsonPath('data.dosage', null)
        ->assertJsonPath('data.frequency', null)
        ->assertJsonPath('data.notes', null);

    expect(MedicationReconciliation::where('medicine_name', 'Metformin')->sole()->dosage)->toBeNull();

    // A real value is kept, not nulled. Laravel's global TrimStrings middleware
    // means surrounding whitespace is already gone by the time validation runs.
    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/medication-reconciliations", [
            'medicine_name' => 'Aspirin',
            'dosage' => '75 mg',
        ])
        ->assertCreated()
        ->assertJsonPath('data.dosage', '75 mg');
});

test('a known dose can be corrected back to unknown on update', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);
    $medRec = mrc_medrec($doctor, $patient, ['dosage' => '850mg', 'frequency' => 'twice daily']);

    // Clearing a figure that no longer matches what the patient takes is a
    // legitimate correction, so null must be accepted on update as well as
    // rejected.
    $this->actingAs($doctor)
        ->putJson("/api/v1/medication-reconciliations/{$medRec->id}", [
            'dosage' => null,
            'frequency' => null,
            'version' => $medRec->version,
        ])
        ->assertOk()
        ->assertJsonPath('data.dosage', null);

    expect($medRec->fresh()->dosage)->toBeNull();
});

test('medication reconciliation can be updated and deactivated', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);
    $medRec = mrc_medrec($doctor, $patient);

    $this->actingAs($doctor)
        ->putJson("/api/v1/medication-reconciliations/{$medRec->id}", [
            'dosage' => '850mg',
        ])
        ->assertOk()
        ->assertJsonPath('data.dosage', '850mg');

    $this->actingAs($doctor)
        ->postJson("/api/v1/medication-reconciliations/{$medRec->id}/deactivate")
        ->assertOk();

    expect($medRec->fresh()->is_active)->toBeFalse();

    $this->actingAs($doctor)
        ->getJson("/api/v1/patients/{$patient->id}/medication-reconciliations")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('medications can be bulk reconciled for an encounter', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => Encounter::STATUS_DRAFT,
    ]);

    mrc_medrec($doctor, $patient, [
        'encounter_id' => $encounter->id,
        'medicine_name' => 'Aspirin',
    ]);

    $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$encounter->id}/medication-reconciliations/reconcile", [
            'medications' => [
                [
                    'medicine_name' => 'Metformin',
                    'dosage' => '500mg',
                    'frequency' => 'BD',
                    'source' => 'clinician',
                ],
                [
                    'medicine_name' => 'Atorvastatin',
                    'dosage' => '20mg',
                    'frequency' => 'OD',
                    'source' => 'prescription_snapshot',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    // The two submitted medicines are active for the encounter, and the
    // pre-existing Aspirin is retired because it is absent from the new list.
    expect(MedicationReconciliation::where('encounter_id', $encounter->id)->where('is_active', true)->count())->toBe(2)
        ->and(MedicationReconciliation::where('encounter_id', $encounter->id)
            ->where('medicine_name', 'Aspirin')
            ->where('is_active', false)
            ->count())->toBe(1);
});

test('medication reconciliation is denied without the manage permission', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);
    $receptionist = User::factory()->create(['role' => 'Receptionist', 'branch_id' => $doctor->branch_id]);

    $this->actingAs($receptionist)
        ->postJson("/api/v1/patients/{$patient->id}/medication-reconciliations", [
            'medicine_name' => 'Metformin',
            'dosage' => '500mg',
            'frequency' => 'BD',
        ])
        ->assertStatus(403);
});

test('medication reconciliation writes an audit trail', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);
    $medRec = mrc_medrec($doctor, $patient);

    $this->actingAs($doctor)
        ->putJson("/api/v1/medication-reconciliations/{$medRec->id}", ['dosage' => '850mg'])
        ->assertOk();

    $audit = AuditLog::query()
        ->where('action', 'medication_reconciliation.updated')
        ->where('auditable_id', $medRec->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($doctor->id)
        ->and($audit->new_values['dosage'])->toBe('850mg');
});

test('the API exposes the version so a client can use it as a precondition', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);
    $medrec = mrc_medrec($doctor, $patient);

    $response = $this->actingAs($doctor)
        ->getJson("/api/v1/patients/{$patient->id}/medication-reconciliations")
        ->assertOk();

    expect($response->json('data.0.version'))->toBe((int) $medrec->version)
        ->and($response->json('data.0'))->toHaveKey('updated_at');
});

test('an update carrying the current version succeeds and returns the new version', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);
    $medrec = mrc_medrec($doctor, $patient);

    $this->actingAs($doctor)
        ->putJson("/api/v1/medication-reconciliations/{$medrec->id}", [
            'dosage' => '850mg',
            'version' => (int) $medrec->version,
        ])
        ->assertOk()
        ->assertJsonPath('data.dosage', '850mg')
        ->assertJsonPath('data.version', (int) $medrec->version + 1);
});

test('an update carrying a stale version is refused with 409 and the current version', function () {
    $first = User::factory()->create(['role' => 'Doctor', 'name' => 'Dr. Aslam']);
    $second = User::factory()->create(['role' => 'Doctor', 'name' => 'Dr. Hina']);
    $patient = mrc_patient($first);
    $medrec = mrc_medrec($first, $patient);
    $staleVersion = (int) $medrec->version;

    $this->actingAs($second)
        ->putJson("/api/v1/medication-reconciliations/{$medrec->id}", [
            'dosage' => '1000mg',
            'version' => $staleVersion,
        ])
        ->assertOk();

    $conflict = $this->actingAs($first)
        ->putJson("/api/v1/medication-reconciliations/{$medrec->id}", [
            'dosage' => '850mg',
            'version' => $staleVersion,
        ])
        ->assertStatus(409)
        ->assertJsonPath('success', false);

    // The response tells the client exactly what to reload, so 409 is
    // actionable rather than a dead end.
    expect($conflict->json('data.expected_version'))->toBe($staleVersion)
        ->and($conflict->json('data.current_version'))->toBe($staleVersion + 1)
        ->and($conflict->json('data.changed_by'))->toBe('Dr. Hina')
        ->and($conflict->json('message'))->toContain('changed by someone else');

    // The first clinician's edit was not applied and not silently discarded.
    expect($medrec->fresh()->dosage)->toBe('1000mg');
});

test('a deactivate carrying a stale version is refused with 409', function () {
    $first = User::factory()->create(['role' => 'Doctor', 'name' => 'Dr. Aslam']);
    $second = User::factory()->create(['role' => 'Doctor', 'name' => 'Dr. Hina']);
    $patient = mrc_patient($first);
    $medrec = mrc_medrec($first, $patient);
    $staleVersion = (int) $medrec->version;

    $this->actingAs($second)
        ->putJson("/api/v1/medication-reconciliations/{$medrec->id}", [
            'dosage' => '1000mg',
            'version' => $staleVersion,
        ])
        ->assertOk();

    $this->actingAs($first)
        ->postJson("/api/v1/medication-reconciliations/{$medrec->id}/deactivate", [
            'version' => $staleVersion,
        ])
        ->assertStatus(409)
        ->assertJsonPath('success', false);

    expect($medrec->fresh()->is_active)->toBeTrue();
});

test('a deactivate carrying the current version succeeds', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);
    $medrec = mrc_medrec($doctor, $patient);

    $this->actingAs($doctor)
        ->postJson("/api/v1/medication-reconciliations/{$medrec->id}/deactivate", [
            'version' => (int) $medrec->version,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($medrec->fresh()->is_active)->toBeFalse();
});

test('a version below one is rejected by validation', function () {
    $doctor = mrc_doctor();
    $patient = mrc_patient($doctor);
    $medrec = mrc_medrec($doctor, $patient);

    $this->actingAs($doctor)
        ->putJson("/api/v1/medication-reconciliations/{$medrec->id}", [
            'dosage' => '850mg',
            'version' => 0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('version');
});
