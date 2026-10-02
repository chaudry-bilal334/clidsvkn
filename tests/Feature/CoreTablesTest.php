<?php

use App\Models\Clinic;
use App\Models\Consent;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

test('a clinic record persists with its identity fields', function () {
    $clinic = Clinic::factory()->create(['name' => 'Downtown Health Clinic']);

    expect(Clinic::query()->find($clinic->id)->name)->toBe('Downtown Health Clinic')
        ->and($clinic->is_active)->toBeTrue();
});

test('duplicate clinic names are rejected by the database', function () {
    Clinic::factory()->create(['name' => 'Downtown Health Clinic']);

    expect(fn () => Clinic::factory()->create(['name' => 'Downtown Health Clinic']))
        ->toThrow(QueryException::class);

    expect(Clinic::count())->toBe(1);
});

test('a doctor profile links to a doctor-role user account', function () {
    $doctor = Doctor::factory()->create();

    expect($doctor->user->role)->toBe('Doctor')
        ->and($doctor->license_number)->not->toBeNull()
        ->and($doctor->user->doctorProfile->is($doctor))->toBeTrue();
});

test('duplicate doctor licence numbers are rejected by the database', function () {
    $doctor = Doctor::factory()->create();

    expect(fn () => Doctor::factory()->create(['license_number' => $doctor->license_number]))
        ->toThrow(QueryException::class);

    expect(Doctor::count())->toBe(1);
});

test('a staff record persists with its employment fields', function () {
    $staff = Staff::factory()->create(['position' => 'Accountant', 'department' => 'administration']);

    expect($staff->user_id)->not->toBeNull()
        ->and((float) $staff->salary)->toBeGreaterThan(0)
        ->and($staff->is_active)->toBeTrue();
});

test('a consent record links to its patient and the staff member who obtained it', function () {
    $patient = Patient::factory()->create();
    $obtainer = User::factory()->create();
    $consent = Consent::factory()->create([
        'patient_id' => $patient->id,
        'user_id' => $obtainer->id,
        'consent_type' => 'treatment',
    ]);

    expect($consent->patient->is($patient))->toBeTrue()
        ->and($consent->obtainedBy->is($obtainer))->toBeTrue()
        ->and($patient->consents()->first()->id)->toBe($consent->id);
});

test('a patient allergy record links to its patient and allergen and feeds the safety data', function () {
    $patient = Patient::factory()->create();
    $allergy = PatientAllergy::factory()->create([
        'patient_id' => $patient->id,
        'severity' => 'severe',
    ]);

    expect($allergy->patient->is($patient))->toBeTrue()
        ->and($allergy->allergen)->not->toBeNull()
        ->and($patient->patientAllergies()->first()->id)->toBe($allergy->id)
        ->and(DB::table('patient_allergies')->where('patient_id', $patient->id)->count())->toBe(1);
});
