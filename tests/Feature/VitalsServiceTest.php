<?php

use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Models\Vital;
use App\Services\VitalsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

pest()->use(RefreshDatabase::class);

function vitals_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function vitals_patient(?User $doctor = null): Patient
{
    $doctor = $doctor ?? vitals_doctor();

    return Patient::factory()->create([
        'branch_id' => $doctor->branch_id,
    ]);
}

function vitals_encounter(?User $doctor = null, ?Patient $patient = null): Encounter
{
    $doctor = $doctor ?? vitals_doctor();
    $patient = $patient ?? vitals_patient($doctor);

    return Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'draft',
    ]);
}

test('vitals can be recorded for a draft encounter', function () {
    $doctor = vitals_doctor();
    $patient = vitals_patient($doctor);
    $encounter = vitals_encounter($doctor, $patient);

    $service = app(VitalsService::class);
    $vital = $service->record($encounter, $doctor, [
        'blood_pressure' => '120/80',
        'temperature' => 37.0,
        'heart_rate' => 72,
        'weight' => 70.5,
    ]);

    expect($vital->encounter_id)->toBe($encounter->id)
        ->and($vital->blood_pressure)->toBe('120/80')
        ->and((float) $vital->temperature)->toBe(37.0)
        ->and($vital->heart_rate)->toBe(72)
        ->and((float) $vital->weight)->toBe(70.5)
        ->and($vital->recorded_by)->toBe($doctor->id);

    // Audit log - use snake_case attribute name (new_values) as per DB column
    $audit = AuditLog::query()->where('action', 'encounter.vitals_recorded')->first();
    expect($audit)->not->toBeNull();
    expect($audit->user_id)->toBe($doctor->id)
        ->and($audit->auditable_id)->toBe($encounter->id)
        ->and($audit->new_values['blood_pressure'])->toBe('120/80')
        ->and($audit->new_values['temperature'])->toBe('37.0');
});

test('vitals cannot be recorded for a finalized encounter', function () {
    $doctor = vitals_doctor();
    $patient = vitals_patient($doctor);
    $encounter = vitals_encounter($doctor, $patient);
    $encounter->status = 'finalized';
    $encounter->save();

    $service = app(VitalsService::class);

    expect(fn () => $service->record($encounter, $doctor, [
        'blood_pressure' => '120/80',
    ]))->toThrow(ValidationException::class);
});

test('vitals can be updated', function () {
    $doctor = vitals_doctor();
    $patient = vitals_patient($doctor);
    $encounter = vitals_encounter($doctor, $patient);
    $service = app(VitalsService::class);

    $vital = $service->record($encounter, $doctor, [
        'blood_pressure' => '120/80',
        'temperature' => 37.0,
        'heart_rate' => 72,
        'weight' => 70.5,
    ]);

    $updated = $service->update($vital, $doctor, [
        'blood_pressure' => '130/85',
        'heart_rate' => 75,
    ]);

    expect($updated->blood_pressure)->toBe('130/85')
        ->and($updated->heart_rate)->toBe(75)
        ->and((float) $updated->temperature)->toBe(37.0); // unchanged

    // Audit log for update
    $audit = AuditLog::query()->where('action', 'encounter.vitals_updated')->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_values['blood_pressure'])->toBe('120/80')
        ->and($audit->new_values['blood_pressure'])->toBe('130/85');
});

test('vitals can be deleted', function () {
    $doctor = vitals_doctor();
    $patient = vitals_patient($doctor);
    $encounter = vitals_encounter($doctor, $patient);
    $service = app(VitalsService::class);

    $vital = $service->record($encounter, $doctor, [
        'blood_pressure' => '120/80',
    ]);

    $result = $service->delete($vital, $doctor);

    expect($result)->toBeTrue();
    expect(Vital::find($vital->id))->toBeNull();

    $audit = AuditLog::query()->where('action', 'encounter.vitals_deleted')->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_values['blood_pressure'])->toBe('120/80');
});

test('vitals cannot be updated on finalized encounter', function () {
    $doctor = vitals_doctor();
    $patient = vitals_patient($doctor);
    $encounter = vitals_encounter($doctor, $patient);
    $encounter->status = 'finalized';
    $encounter->save();

    $service = app(VitalsService::class);
    $vital = Vital::factory()->create(['encounter_id' => $encounter->id]);

    expect(fn () => $service->update($vital, $doctor, ['blood_pressure' => '130/85']))
        ->toThrow(ValidationException::class);
});
