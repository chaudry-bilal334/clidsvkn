<?php

use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\MedicationReconciliation;
use App\Models\Patient;
use App\Models\User;
use App\Services\MedicationReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function medrec_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function medrec_patient(?User $doctor = null): Patient
{
    $doctor = $doctor ?? medrec_doctor();

    return Patient::factory()->create([
        'branch_id' => $doctor->branch_id,
    ]);
}

function medrec_encounter(?User $doctor = null, ?Patient $patient = null): Encounter
{
    $doctor = $doctor ?? medrec_doctor();
    $patient = $patient ?? medrec_patient($doctor);

    return Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'draft',
    ]);
}

test('medication reconciliation can be created', function () {
    $doctor = medrec_doctor();
    $patient = medrec_patient($doctor);

    $service = app(MedicationReconciliationService::class);
    $medRec = $service->create([
        'patient_id' => $patient->id,
        'encounter_id' => null,
        'medicine_name' => 'Amoxicillin',
        'dosage' => '500mg',
        'frequency' => 'TID',
        'source' => MedicationReconciliation::SOURCE_CLINICIAN,
        'is_active' => true,
        'notes' => 'Test notes',
    ], User::findOrFail(1));

    expect($medRec->patient_id)->toBe($patient->id)
        ->and($medRec->medicine_name)->toBe('Amoxicillin')
        ->and($medRec->dosage)->toBe('500mg')
        ->and($medRec->frequency)->toBe('TID')
        ->and($medRec->source)->toBe(MedicationReconciliation::SOURCE_CLINICIAN)
        ->and($medRec->is_active)->toBeTrue()
        ->and($medRec->notes)->toBe('Test notes');

    $audit = AuditLog::query()->where('action', 'medication_reconciliation.created')->first();
    expect($audit)->not->toBeNull();
    expect($audit->user_id)->toBe(1)
        ->and($audit->auditable_id)->toBe($medRec->id)
        ->and($audit->new_values['medicine_name'])->toBe('Amoxicillin');
});

test('medication reconciliation can be updated', function () {
    $doctor = medrec_doctor();
    $patient = medrec_patient($doctor);

    $service = app(MedicationReconciliationService::class);
    $medRec = $service->create([
        'patient_id' => $patient->id,
        'encounter_id' => null,
        'medicine_name' => 'Amoxicillin',
        'dosage' => '500mg',
        'frequency' => 'TID',
        'source' => MedicationReconciliation::SOURCE_CLINICIAN,
        'is_active' => true,
        'notes' => 'Test notes',
    ], $doctor);

    $updated = $service->update($medRec, $doctor, [
        'dosage' => '250mg',
        'frequency' => 'BID',
        'notes' => 'Updated notes',
    ]);

    expect($updated->dosage)->toBe('250mg')
        ->and($updated->frequency)->toBe('BID')
        ->and($updated->notes)->toBe('Updated notes');

    $audit = AuditLog::query()->where('action', 'medication_reconciliation.updated')->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_values['dosage'])->toBe('500mg')
        ->and($audit->new_values['dosage'])->toBe('250mg');
});

test('medication reconciliation can be deactivated', function () {
    $doctor = medrec_doctor();
    $patient = medrec_patient($doctor);

    $service = app(MedicationReconciliationService::class);
    $medRec = $service->create([
        'patient_id' => $patient->id,
        'encounter_id' => null,
        'medicine_name' => 'Test Drug',
        'dosage' => '100mg',
        'frequency' => 'BID',
        'source' => MedicationReconciliation::SOURCE_CLINICIAN,
        'is_active' => true,
        'notes' => 'Test',
    ], $doctor);

    $deactivated = $service->deactivate($medRec, $doctor);

    expect($deactivated->is_active)->toBeFalse();

    $audit = AuditLog::query()->where('action', 'medication_reconciliation.deactivated')->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_values['is_active'])->toBeTrue()
        ->and($audit->new_values['is_active'])->toBeFalse();
});

test('bulk reconcile for encounter', function () {
    $doctor = medrec_doctor();
    $patient = medrec_patient($doctor);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'draft',
    ]);

    // Create existing active medication
    MedicationReconciliation::factory()->create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'medicine_name' => 'Existing Drug',
        'dosage' => '100mg',
        'frequency' => 'BID',
        'source' => MedicationReconciliation::SOURCE_CLINICIAN,
        'is_active' => true,
    ]);

    $service = app(MedicationReconciliationService::class);
    $result = $service->reconcileForEncounter($encounter->id, $doctor, [
        [
            'medicine_name' => 'Existing Drug',
            'dosage' => '200mg', // changed
            'frequency' => 'BID',
            'source' => MedicationReconciliation::SOURCE_CLINICIAN,
            'is_active' => true,
            'notes' => 'Updated dosage',
        ],
        [
            'medicine_name' => 'New Drug',
            'dosage' => '50mg',
            'frequency' => 'TID',
            'source' => MedicationReconciliation::SOURCE_CLINICIAN,
            'is_active' => true,
            'notes' => 'New medication',
        ],
    ]);

    expect($result['created'])->toHaveCount(1)
        ->and($result['updated'])->toHaveCount(1)
        ->and($result['deactivated'])->toHaveCount(0);

    $updated = MedicationReconciliation::where('medicine_name', 'Existing Drug')->first();
    expect($updated->dosage)->toBe('200mg');

    $created = MedicationReconciliation::where('medicine_name', 'New Drug')->first();
    expect($created)->not->toBeNull();
    expect($created->dosage)->toBe('50mg');
});
