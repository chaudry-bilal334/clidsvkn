<?php

use App\Models\AuditLog;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Services\DiagnosisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

pest()->use(RefreshDatabase::class);

function diag_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function diag_patient(?User $doctor = null): Patient
{
    $doctor = $doctor ?? diag_doctor();

    return Patient::factory()->create([
        'branch_id' => $doctor->branch_id,
    ]);
}

function diag_encounter(?User $doctor = null, ?Patient $patient = null): Encounter
{
    $doctor = $doctor ?? diag_doctor();
    $patient = $patient ?? diag_patient($doctor);

    return Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'draft',
    ]);
}

test('diagnosis can be recorded for a draft encounter', function () {
    $doctor = diag_doctor();
    $patient = diag_patient($doctor);
    $encounter = diag_encounter($doctor, $patient);

    $service = app(DiagnosisService::class);
    $diagnosis = $service->record($encounter, $doctor, 'A09.9', 'Acute gastroenteritis');

    expect($diagnosis->encounter_id)->toBe($encounter->id)
        ->and($diagnosis->icd_code)->toBe('A09.9')
        ->and($diagnosis->description)->toBe('Acute gastroenteritis')
        ->and($diagnosis->recorded_by)->toBe($doctor->id);

    $audit = AuditLog::query()->where('action', 'encounter.diagnosis_recorded')->first();
    expect($audit)->not->toBeNull();
    expect($audit->user_id)->toBe($doctor->id)
        ->and($audit->auditable_id)->toBe($encounter->id)
        ->and($audit->new_values['icd_code'])->toBe('A09.9')
        ->and($audit->new_values['description'])->toBe('Acute gastroenteritis');
});

test('diagnosis cannot be recorded for a finalized encounter', function () {
    $doctor = diag_doctor();
    $patient = diag_patient($doctor);
    $encounter = diag_encounter($doctor, $patient);
    $encounter->status = 'finalized';
    $encounter->save();

    $service = app(DiagnosisService::class);

    expect(fn () => $service->record($encounter, $doctor, 'A09.9'))
        ->toThrow(ValidationException::class);
});

test('diagnosis can be updated', function () {
    $doctor = diag_doctor();
    $patient = diag_patient($doctor);
    $encounter = diag_encounter($doctor, $patient);
    $service = app(DiagnosisService::class);

    $diagnosis = $service->record($encounter, $doctor, 'A09.9', 'Acute gastroenteritis');

    $updated = $service->update($diagnosis, $doctor, 'A09.0', 'Cholera');

    expect($updated->icd_code)->toBe('A09.0')
        ->and($updated->description)->toBe('Cholera');

    $audit = AuditLog::query()->where('action', 'encounter.diagnosis_updated')->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_values['icd_code'])->toBe('A09.9')
        ->and($audit->new_values['icd_code'])->toBe('A09.0');
});

test('diagnosis can be deleted', function () {
    $doctor = diag_doctor();
    $patient = diag_patient($doctor);
    $encounter = diag_encounter($doctor, $patient);
    $service = app(DiagnosisService::class);

    $diagnosis = $service->record($encounter, $doctor, 'A09.9', 'Test');

    $result = $service->delete($diagnosis, $doctor);

    expect($result)->toBeTrue();
    expect(Diagnosis::find($diagnosis->id))->toBeNull();

    $audit = AuditLog::query()->where('action', 'encounter.diagnosis_deleted')->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_values['icd_code'])->toBe('A09.9');
});

test('diagnosis cannot be updated on finalized encounter', function () {
    $doctor = diag_doctor();
    $patient = diag_patient($doctor);
    $encounter = diag_encounter($doctor, $patient);
    $encounter->status = 'finalized';
    $encounter->save();

    $service = app(DiagnosisService::class);
    $diagnosis = Diagnosis::factory()->create(['encounter_id' => $encounter->id]);

    expect(fn () => $service->update($diagnosis, $doctor, 'A09.0', 'Updated'))
        ->toThrow(ValidationException::class);
});
