<?php

use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Services\EncounterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

pest()->use(RefreshDatabase::class);

function enc_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function enc_patient(?User $doctor = null): Patient
{
    $doctor = $doctor ?? enc_doctor();

    return Patient::factory()->create([
        'branch_id' => $doctor->branch_id,
    ]);
}

test('encounter can be opened', function () {
    $doctor = enc_doctor();
    $patient = enc_patient($doctor);

    $service = app(EncounterService::class);
    $encounter = $service->openEncounter($patient->id, $doctor, null, 'Test notes');

    expect($encounter->patient_id)->toBe($patient->id)
        ->and($encounter->doctor_id)->toBe($doctor->id)
        ->and($encounter->status)->toBe(Encounter::STATUS_DRAFT)
        ->and($encounter->notes)->toBe('Test notes');

    $audit = AuditLog::query()->where('action', 'encounter.created')->first();
    expect($audit)->not->toBeNull();
    expect($audit->user_id)->toBe($doctor->id)
        ->and($audit->auditable_id)->toBe($encounter->id)
        ->and($audit->new_values['status'])->toBe(Encounter::STATUS_DRAFT);
});

test('encounter can be finalized', function () {
    $doctor = enc_doctor();
    $patient = enc_patient($doctor);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'draft',
    ]);

    $service = app(EncounterService::class);
    $finalized = $service->finalize($encounter, $doctor);

    expect($finalized->status)->toBe(Encounter::STATUS_FINALIZED)
        ->and($finalized->finalized_at)->not->toBeNull()
        ->and($finalized->finalized_by)->toBe($doctor->id);

    $audit = AuditLog::query()->where('action', 'encounter.finalized')->first();
    expect($audit)->not->toBeNull();
    expect($audit->new_values['status'])->toBe(Encounter::STATUS_FINALIZED)
        ->and($audit->new_values['finalized_by'])->toBe($doctor->id);
});

test('encounter cannot be finalized twice', function () {
    $doctor = enc_doctor();
    $patient = enc_patient($doctor);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'finalized',
    ]);

    $service = app(EncounterService::class);

    expect(fn () => $service->finalize($encounter, $doctor))
        ->toThrow(ValidationException::class);
});

test('encounter can be voided', function () {
    $doctor = enc_doctor();
    $patient = enc_patient($doctor);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'draft',
    ]);

    $service = app(EncounterService::class);
    $voided = $service->void($encounter, $doctor, 'Test void reason');

    expect($voided->status)->toBe(Encounter::STATUS_VOIDED)
        ->and($voided->voided_at)->not->toBeNull()
        ->and($voided->voided_by)->toBe($doctor->id)
        ->and($voided->void_reason)->toBe('Test void reason');

    $audit = AuditLog::query()->where('action', 'encounter.voided')->first();
    expect($audit)->not->toBeNull();
    expect($audit->new_values['void_reason'])->toBe('Test void reason');
});

test('encounter cannot be voided if already voided', function () {
    $doctor = enc_doctor();
    $patient = enc_patient($doctor);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'voided',
    ]);

    $service = app(EncounterService::class);

    expect(fn () => $service->void($encounter, $doctor, 'Test'))
        ->toThrow(ValidationException::class);
});

test('encounter can be amended', function () {
    $doctor = enc_doctor();
    $patient = enc_patient($doctor);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'finalized',
        'notes' => 'Original notes',
    ]);

    $service = app(EncounterService::class);
    $amendment = $service->amend($encounter, $doctor);

    expect($amendment->status)->toBe(Encounter::STATUS_DRAFT)
        ->and($amendment->amends_encounter_id)->toBe($encounter->id)
        ->and($amendment->notes)->toBe('Original notes');

    $audit = AuditLog::query()->where('action', 'encounter.amended')->first();
    expect($audit)->not->toBeNull();
    expect($audit->new_values['amends_encounter_id'])->toBe($encounter->id);
});

test('vitals can be recorded', function () {
    $doctor = enc_doctor();
    $patient = enc_patient($doctor);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'draft',
    ]);

    $service = app(EncounterService::class);
    $vital = $service->recordVitals($encounter, $doctor, [
        'blood_pressure' => '120/80',
        'temperature' => 37.0,
        'heart_rate' => 72,
        'weight' => 70.5,
    ]);

    expect($vital->encounter_id)->toBe($encounter->id)
        ->and($vital->blood_pressure)->toBe('120/80')
        ->and((float) $vital->temperature)->toBe(37.0);

    $audit = AuditLog::query()->where('action', 'encounter.vitals_recorded')->first();
    expect($audit)->not->toBeNull();
    expect($audit->new_values['blood_pressure'])->toBe('120/80');
});

test('diagnosis can be recorded', function () {
    $doctor = enc_doctor();
    $patient = enc_patient($doctor);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'draft',
    ]);

    $service = app(EncounterService::class);
    $diagnosis = $service->recordDiagnosis($encounter, $doctor, 'A09.9', 'Acute gastroenteritis');

    expect($diagnosis->encounter_id)->toBe($encounter->id)
        ->and($diagnosis->icd_code)->toBe('A09.9')
        ->and($diagnosis->description)->toBe('Acute gastroenteritis');

    $audit = AuditLog::query()->where('action', 'encounter.diagnosis_recorded')->first();
    expect($audit)->not->toBeNull();
    expect($audit->new_values['icd_code'])->toBe('A09.9');
});

test('encounter cannot be finalized if not in draft', function () {
    $doctor = enc_doctor();
    $patient = enc_patient($doctor);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'in_progress',
    ]);

    $service = app(EncounterService::class);

    expect(fn () => $service->finalize($encounter, $doctor))
        ->toThrow(ValidationException::class);
});

test('encounter can be amended from voided', function () {
    $doctor = enc_doctor();
    $patient = enc_patient($doctor);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'voided',
    ]);

    $service = app(EncounterService::class);
    $amendment = $service->amend($encounter, $doctor);

    expect($amendment->status)->toBe(Encounter::STATUS_DRAFT)
        ->and($amendment->amends_encounter_id)->toBe($encounter->id);
});
