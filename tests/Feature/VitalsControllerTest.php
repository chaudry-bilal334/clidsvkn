<?php

use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function vc_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function vc_patient(?User $doctor = null): Patient
{
    $doctor = $doctor ?? vc_doctor();

    return Patient::factory()->create([
        'branch_id' => $doctor->branch_id,
    ]);
}

function vc_encounter(?User $doctor = null, ?Patient $patient = null): Encounter
{
    $doctor = $doctor ?? vc_doctor();
    $patient = $patient ?? vc_patient($doctor);

    return Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => 'draft',
    ]);
}

test('vitals can be listed', function () {
    $doctor = vc_doctor();
    $patient = vc_patient($doctor);
    $encounter = vc_encounter($doctor, $patient);

    Vital::factory()->count(3)->create(['encounter_id' => $encounter->id]);

    $response = $this->actingAs($doctor)
        ->getJson("/api/v1/encounters/{$encounter->id}/vitals")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');

    expect($response->json('data.0'))->toHaveKeys([
        'id', 'encounter_id', 'blood_pressure', 'temperature',
        'heart_rate', 'weight', 'recorded_by', 'recorded_by_name', 'recorded_at',
    ]);
});

test('vitals can be created', function () {
    $doctor = vc_doctor();
    $patient = vc_patient($doctor);
    $encounter = vc_encounter($doctor, $patient);

    $response = $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$encounter->id}/vitals", [
            'blood_pressure' => '120/80',
            'temperature' => 37.0,
            'heart_rate' => 72,
            'weight' => 70.5,
        ])
        ->assertCreated()
        ->assertJsonPath('data.blood_pressure', '120/80')
        ->assertJsonPath('data.temperature', '37.0')
        ->assertJsonPath('data.heart_rate', 72);

    expect(Vital::count())->toBe(1);
});

test('vitals cannot be created for finalized encounter', function () {
    $doctor = vc_doctor();
    $patient = vc_patient($doctor);
    $encounter = vc_encounter($doctor, $patient);
    $encounter->status = 'finalized';
    $encounter->save();

    $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$encounter->id}/vitals", [
            'blood_pressure' => '120/80',
        ])
        ->assertStatus(422);
});

test('vitals can be updated', function () {
    $doctor = vc_doctor();
    $patient = vc_patient($doctor);
    $encounter = vc_encounter($doctor, $patient);

    $vital = Vital::factory()->create([
        'encounter_id' => $encounter->id,
        'blood_pressure' => '120/80',
        'temperature' => 37.0,
        'heart_rate' => 72,
        'weight' => 70.5,
        'recorded_by' => $doctor->id,
    ]);

    $response = $this->actingAs($doctor)
        ->putJson("/api/v1/vitals/{$vital->id}", [
            'blood_pressure' => '130/85',
            'heart_rate' => 75,
        ])
        ->assertOk()
        ->assertJsonPath('data.blood_pressure', '130/85')
        ->assertJsonPath('data.heart_rate', 75);

    $audit = AuditLog::query()->where('action', 'encounter.vitals_updated')->first();
    expect($audit)->not->toBeNull();
});

test('vitals can be deleted', function () {
    $doctor = vc_doctor();
    $patient = vc_patient($doctor);
    $encounter = vc_encounter($doctor, $patient);

    $vital = Vital::factory()->create([
        'encounter_id' => $encounter->id,
        'blood_pressure' => '120/80',
        'recorded_by' => $doctor->id,
    ]);

    $response = $this->actingAs($doctor)
        ->deleteJson("/api/v1/vitals/{$vital->id}")
        ->assertOk();

    expect(Vital::find($vital->id))->toBeNull();
});

test('vitals cannot be updated on finalized encounter', function () {
    $doctor = vc_doctor();
    $patient = vc_patient($doctor);
    $encounter = vc_encounter($doctor, $patient);
    $encounter->status = 'finalized';
    $encounter->save();

    $vital = Vital::factory()->create([
        'encounter_id' => $encounter->id,
        'blood_pressure' => '120/80',
        'recorded_by' => $doctor->id,
    ]);

    $this->actingAs($doctor)
        ->putJson("/api/v1/vitals/{$vital->id}", [
            'blood_pressure' => '130/85',
        ])
        ->assertStatus(422);
});
