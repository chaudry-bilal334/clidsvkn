<?php

use App\Models\Allergen;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\PatientMedicalHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

test('patient safety banner returns allergies', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $patient = Patient::factory()->create();

    // Create an allergen and associate it with the patient
    $pen = Allergen::factory()->create([
        'name' => 'Penicillin',
        'family' => 'penicillin',
    ]);

    $patient->medicalHistories()->create([
        'category' => PatientMedicalHistory::CATEGORY_ALLERGY,
        'allergen_id' => $pen->id,
        'reaction' => 'Rash, anaphylaxis',
        'severity' => PatientMedicalHistory::SEVERITY_SEVERE,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/patients/'.$patient->id.'/safety-banner');
    $response->assertOk();

    $data = $response->json('data');
    $allergies = $data['allergies'];

    expect($allergies)->toHaveCount(1);
    expect($allergies[0]['name'])->toBe('Penicillin');
    expect($allergies[0]['severity'])->toBe('severe');
});

test('patient safety banner returns critical history', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $patient = Patient::factory()->create();

    // Create a condition in critical history
    $patient->medicalHistories()->create([
        'category' => PatientMedicalHistory::CATEGORY_CONDITION,
        'term' => 'Hypertension',
        'severity' => PatientMedicalHistory::SEVERITY_MODERATE,
        'is_active' => true,
        'notes' => 'High blood pressure diagnosed 2020',
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/patients/'.$patient->id.'/safety-banner');
    $response->assertOk();

    $data = $response->json('data');
    $criticalHistory = $data['critical_history'];

    expect($criticalHistory)->toHaveCount(1);
    expect($criticalHistory[0]['term'])->toBe('Hypertension');
    expect($criticalHistory[0]['severity'])->toBe('moderate');
});

test('patient safety banner returns current medications', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $patient = Patient::factory()->create();

    // Create medication reconciliation entry
    DB::table('medication_reconciliations')->insert([
        'patient_id' => $patient->id,
        'medicine_name' => 'Paracetamol 500mg',
        'dosage' => '500 mg',
        'frequency' => 'three times daily',
        'source' => 'self_reported',
        'is_active' => true,
        'reconciled_at' => now(),
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/patients/'.$patient->id.'/safety-banner');
    $response->assertOk();

    $data = $response->json('data');
    $medications = $data['current_medications'];

    expect($medications)->toHaveCount(1);
    expect($medications[0]['medicine_name'])->toBe('Paracetamol 500mg');
});

test('patient safety banner lists allergies from the dedicated patient_allergies table', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $patient = Patient::factory()->create();

    PatientAllergy::factory()->create([
        'patient_id' => $patient->id,
        'severity' => 'life_threatening',
        'reaction' => 'Anaphylaxis',
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/patients/'.$patient->id.'/safety-banner');
    $response->assertOk();

    $allergies = $response->json('data.allergies');

    expect($allergies)->toHaveCount(1)
        ->and($allergies[0]['severity'])->toBe('life_threatening')
        ->and($allergies[0]['reaction'])->toBe('Anaphylaxis');
});
