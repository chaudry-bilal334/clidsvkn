<?php

use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function dc_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function dc_patient(?User $doctor = null): Patient
{
    $doctor = $doctor ?? dc_doctor();

    return Patient::factory()->create([
        'branch_id' => $doctor->branch_id,
    ]);
}

function dc_encounter(?User $doctor = null, ?Patient $patient = null): Encounter
{
    $doctor = $doctor ?? dc_doctor();
    $patient = $patient ?? dc_patient($doctor);

    return Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'draft',
    ]);
}

test('diagnoses can be listed', function () {
    $doctor = dc_doctor();
    $patient = dc_patient($doctor);
    $encounter = dc_encounter($doctor, $patient);

    Diagnosis::factory()->count(2)->create(['encounter_id' => $encounter->id]);

    $response = $this->actingAs($doctor)
        ->getJson("/api/v1/encounters/{$encounter->id}/diagnoses")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');
});

test('diagnosis can be created', function () {
    $doctor = dc_doctor();
    $patient = dc_patient($doctor);
    $encounter = dc_encounter($doctor, $patient);

    $response = $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$encounter->id}/diagnoses", [
            'icd_code' => 'A09.9',
            'description' => 'Acute gastroenteritis',
        ])
        ->assertCreated()
        ->assertJsonPath('data.icd_code', 'A09.9')
        ->assertJsonPath('data.description', 'Acute gastroenteritis');

    expect(Diagnosis::count())->toBe(1);
});

test('diagnosis cannot be created for finalized encounter', function () {
    $doctor = dc_doctor();
    $patient = dc_patient($doctor);
    $encounter = dc_encounter($doctor, $patient);
    $encounter->status = 'finalized';
    $encounter->save();

    $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$encounter->id}/diagnoses", [
            'icd_code' => 'A09.9',
        ])
        ->assertStatus(422);
});

test('diagnosis can be updated', function () {
    $doctor = dc_doctor();
    $patient = dc_patient($doctor);
    $encounter = dc_encounter($doctor, $patient);

    $diagnosis = Diagnosis::factory()->create([
        'encounter_id' => $encounter->id,
        'icd_code' => 'A09.9',
        'description' => 'Original',
        'recorded_by' => $doctor->id,
    ]);

    $response = $this->actingAs($doctor)
        ->putJson("/api/v1/diagnoses/{$diagnosis->id}", [
            'icd_code' => 'A09.0',
            'description' => 'Updated',
        ])
        ->assertOk()
        ->assertJsonPath('data.icd_code', 'A09.0')
        ->assertJsonPath('data.description', 'Updated');
});

test('diagnosis can be deleted', function () {
    $doctor = dc_doctor();
    $patient = dc_patient($doctor);
    $encounter = dc_encounter($doctor, $patient);

    $diagnosis = Diagnosis::factory()->create([
        'encounter_id' => $encounter->id,
        'icd_code' => 'A09.9',
        'recorded_by' => $doctor->id,
    ]);

    $response = $this->actingAs($doctor)
        ->deleteJson("/api/v1/diagnoses/{$diagnosis->id}")
        ->assertOk();

    expect(Diagnosis::find($diagnosis->id))->toBeNull();
});

test('diagnosis cannot be updated on finalized encounter', function () {
    $doctor = dc_doctor();
    $patient = dc_patient($doctor);
    $encounter = dc_encounter($doctor, $patient);
    $encounter->status = 'finalized';
    $encounter->save();

    $diagnosis = Diagnosis::factory()->create([
        'encounter_id' => $encounter->id,
        'icd_code' => 'A09.9',
        'recorded_by' => $doctor->id,
    ]);

    $this->actingAs($doctor)
        ->putJson("/api/v1/diagnoses/{$diagnosis->id}", [
            'icd_code' => 'A09.0',
        ])
        ->assertStatus(422);
});
