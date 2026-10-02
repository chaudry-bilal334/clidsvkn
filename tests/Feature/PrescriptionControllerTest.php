<?php

use App\Models\Allergen;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function rx_doctor_controller(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function rx_patient_controller(?User $doctor = null): Patient
{
    $doctor = $doctor ?? rx_doctor_controller();

    return Patient::factory()->create([
        'branch_id' => $doctor->branch_id,
    ]);
}

test('prescription creation endpoint ignores client-supplied status and forces pending', function () {
    $doctor = rx_doctor_controller();
    $patient = rx_patient_controller($doctor);

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'items' => [
                [
                    'medicine_name' => 'Amoxicillin',
                    'dosage' => '500mg',
                    'frequency' => 'TID',
                    'duration' => '7 days',
                    'instructions' => 'Take with food',
                    'quantity' => 21,
                ],
            ],
            // Client tries to force a status - should be ignored
            'status' => 'completed',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', Prescription::STATUS_PENDING);

    expect(Prescription::firstOrFail()->status)->toBe(Prescription::STATUS_PENDING);
});

test('prescription creation rejects empty items', function () {
    $doctor = rx_doctor_controller();
    $patient = rx_patient_controller($doctor);

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'items' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.items', fn ($errors) => count($errors) > 0);
});

test('prescription creation with safety warnings requires override reason', function () {
    $doctor = rx_doctor_controller();
    $patient = rx_patient_controller($doctor);

    // Create an allergen that will trigger a warning
    $allergen = Allergen::factory()->create(['name' => 'Penicillin', 'family' => 'penicillin']);
    PatientMedicalHistory::factory()->create([
        'patient_id' => $patient->id,
        'category' => PatientMedicalHistory::CATEGORY_ALLERGY,
        'allergen_id' => $allergen->id,
        'term' => 'Penicillin',
        'severity' => PatientMedicalHistory::SEVERITY_SEVERE,
        'reaction' => 'Anaphylaxis',
        'is_active' => true,
    ]);

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'items' => [
                [
                    'medicine_name' => 'Penicillin',
                    'dosage' => '500mg',
                    'frequency' => 'TID',
                    'duration' => '7 days',
                    'instructions' => 'Take with food',
                    'quantity' => 21,
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.safety_alerts', fn ($alerts) => count($alerts) > 0);
});

test('prescription creation with safety warnings and override reason succeeds', function () {
    $doctor = rx_doctor_controller();
    $patient = rx_patient_controller($doctor);

    $allergen = Allergen::factory()->create(['name' => 'Penicillin', 'family' => 'penicillin']);
    PatientMedicalHistory::factory()->create([
        'patient_id' => $patient->id,
        'category' => PatientMedicalHistory::CATEGORY_ALLERGY,
        'allergen_id' => $allergen->id,
        'term' => 'Penicillin',
        'severity' => PatientMedicalHistory::SEVERITY_MILD,
        'reaction' => 'Rash',
        'is_active' => true,
    ]);

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'items' => [
                [
                    'medicine_name' => 'Penicillin',
                    'dosage' => '500mg',
                    'frequency' => 'TID',
                    'duration' => '7 days',
                    'instructions' => 'Take with food',
                    'quantity' => 21,
                ],
            ],
            'override_reason' => 'Patient monitored, benefits outweigh risk',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', Prescription::STATUS_PENDING);

    // Audit log should contain override record
    $audit = AuditLog::query()->where('action', 'prescription.override')->sole();
    expect($audit->new_values['override_reason'])->toBe('Patient monitored, benefits outweigh risk');
});

test('prescription creation audit log records correct actor', function () {
    $doctor = rx_doctor_controller();
    $patient = rx_patient_controller($doctor);

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'items' => [
                [
                    'medicine_name' => 'Test Drug',
                    'dosage' => '100mg',
                    'frequency' => 'BID',
                    'duration' => '10 days',
                    'instructions' => 'Test',
                    'quantity' => 10,
                ],
            ],
        ])
        ->assertCreated();

    $prescription = Prescription::firstOrFail();
    $audit = AuditLog::query()->where('action', 'prescription.created')->where('auditable_id', $prescription->id)->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($doctor->id)
        ->and($audit->auditable_type)->toBe(Prescription::class)
        ->and($audit->auditable_id)->toBe($prescription->id)
        ->and($audit->new_values['patient_id'])->toBe($patient->id)
        ->and($audit->new_values['doctor_id'])->toBe($doctor->id);
});

test('prescription number follows RX-XXXXX format', function () {
    $doctor = rx_doctor_controller();
    $patient = rx_patient_controller($doctor);

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'items' => [
                [
                    'medicine_name' => 'Test',
                    'dosage' => '100mg',
                    'frequency' => 'BID',
                    'duration' => '5 days',
                    'instructions' => 'Test',
                    'quantity' => 10,
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.prescription_number', fn (string $value) => preg_match('/^RX-\d{5}$/', $value) === 1);
});
