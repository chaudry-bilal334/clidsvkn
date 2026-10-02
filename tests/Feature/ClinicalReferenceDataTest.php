<?php

use App\Console\Commands\ScanAllergyNotesCommand;
use App\Models\Allergen;
use App\Models\DosageRule;
use App\Models\DrugInteractionRule;
use App\Models\MedicationReconciliation;
use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use App\Models\Prescription;
use App\Models\User;
use App\Services\ClinicalDecisionSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

pest()->use(RefreshDatabase::class);

function crd_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function crd_receptionist(): User
{
    return User::factory()->create(['role' => 'Receptionist']);
}

test('clinical reference seeder never produces approved rows', function () {
    $this->seed();

    expect(Allergen::where('is_approved', true)->count())->toBe(0)
        ->and(DrugInteractionRule::where('is_approved', true)->count())->toBe(0)
        ->and(DosageRule::where('is_approved', true)->count())->toBe(0)
        ->and(Allergen::count())->toBe(3)
        ->and(DrugInteractionRule::count())->toBe(4)
        ->and(DosageRule::count())->toBe(6);
});

test('seeded clinical reference data is flagged pending clinical review', function () {
    $this->seed();

    $patient = Patient::factory()->create();
    $doctor = crd_doctor();

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate", [
            'medicines' => ['Warfarin 5mg', 'Ibuprofen 400mg'],
        ])
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.trigger_type', 'drug_interaction')
        ->assertJsonPath('data.0.recommendation', fn ($v) => str_contains($v, '(rule pending clinical review)'));
});

test('approved rules are not flagged pending clinical review', function () {
    $patient = Patient::factory()->create();
    $doctor = crd_doctor();

    DrugInteractionRule::factory()->between('Warfarin', 'Ibuprofen')->approved()->create();

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate", [
            'medicines' => ['Warfarin 5mg', 'Ibuprofen 400mg'],
        ])
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.recommendation', fn ($v) => ! str_contains($v, 'rule pending clinical review'));
});

test('unapproved reference data is flagged until the clinical lead approves it', function () {
    $patient = Patient::factory()->create();
    $doctor = crd_doctor();
    $clinicalLead = User::factory()->create(['role' => 'Admin']);

    $rule = DrugInteractionRule::factory()->between('Warfarin', 'Ibuprofen')->create();

    expect($rule->is_approved)->toBeFalse();

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate", [
            'medicines' => ['Warfarin 5mg', 'Ibuprofen 400mg'],
        ])
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.trigger_type', 'drug_interaction')
        ->assertJsonPath('data.0.recommendation', fn ($v) => str_contains($v, '(rule pending clinical review)'));

    $this->actingAs($clinicalLead)
        ->getJson('/api/v1/reference-data/pending?type=drug_interactions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $rule->id)
        ->assertJsonPath('data.0.is_approved', false);

    $this->actingAs($clinicalLead)
        ->postJson("/api/v1/reference-data/approve/drug_interactions/{$rule->id}")
        ->assertOk()
        ->assertJsonPath('data.is_approved', true);

    expect($rule->fresh()->is_approved)->toBeTrue()
        ->and($rule->fresh()->approved_by)->toBe($clinicalLead->id)
        ->and($rule->fresh()->approved_at)->not->toBeNull();

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate", [
            'medicines' => ['Warfarin 5mg', 'Ibuprofen 400mg'],
        ])
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.recommendation', fn ($v) => ! str_contains($v, 'rule pending clinical review'));
});

test('reject resets an approved reference data row back to pending', function () {
    $clinicalLead = User::factory()->create(['role' => 'Admin']);
    $allergen = Allergen::factory()->approved()->create();

    expect($allergen->is_approved)->toBeTrue();

    $this->actingAs($clinicalLead)
        ->postJson("/api/v1/reference-data/reject/allergens/{$allergen->id}")
        ->assertOk()
        ->assertJsonPath('data.is_approved', false);

    expect($allergen->fresh()->is_approved)->toBeFalse()
        ->and($allergen->fresh()->approved_by)->toBeNull()
        ->and($allergen->fresh()->approved_at)->toBeNull();
});

test('clinical decision support output preserves the trigger_type and message contract', function () {
    $patient = Patient::factory()->create();

    DrugInteractionRule::factory()->between('Warfarin', 'Ibuprofen')->create();

    $service = new ClinicalDecisionSupportService;

    $warnings = $service->checkCandidateMedicines($patient, [
        ['medicine_name' => 'Warfarin 5mg', 'dosage' => '5 mg', 'frequency' => 'once daily'],
        ['medicine_name' => 'Ibuprofen 400mg', 'dosage' => '400 mg', 'frequency' => 'three times daily'],
    ]);

    expect($warnings)->not->toBeEmpty();

    foreach ($warnings as $warning) {
        expect($warning)
            ->toHaveKeys(['trigger_type', 'message'])
            ->and($warning['trigger_type'])->toBeString()
            ->and($warning['message'])->toBeString();
    }
});

test('medical history can be recorded and listed by a clinician', function () {
    $patient = Patient::factory()->create();
    $doctor = crd_doctor();
    $allergen = Allergen::factory()->create();

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/medical-history", [
            'category' => PatientMedicalHistory::CATEGORY_ALLERGY,
            'allergen_id' => $allergen->id,
            'term' => $allergen->name,
            'severity' => PatientMedicalHistory::SEVERITY_SEVERE,
            'notes' => 'Anaphylaxis after first dose.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.category', PatientMedicalHistory::CATEGORY_ALLERGY)
        ->assertJsonPath('data.allergen_id', $allergen->id)
        ->assertJsonPath('data.recorded_by', $doctor->id);

    $this->actingAs($doctor)
        ->getJson("/api/v1/patients/{$patient->id}/medical-history")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('medical history update and validation behave correctly', function () {
    $patient = Patient::factory()->create();
    $doctor = crd_doctor();

    $history = PatientMedicalHistory::factory()->condition('Hypertension')->create([
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($doctor)
        ->putJson("/api/v1/medical-history/{$history->id}", [
            'category' => PatientMedicalHistory::CATEGORY_CONDITION,
            'term' => 'Type 2 Diabetes',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.term', 'Type 2 Diabetes')
        ->assertJsonPath('data.is_active', false);

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/medical-history", [
            'category' => 'not-a-category',
        ])
        ->assertUnprocessable();
});

test('medical history and reconciliation endpoints are restricted by encounters permission', function () {
    $patient = Patient::factory()->create();
    $receptionist = crd_receptionist();

    $this->actingAs($receptionist)
        ->getJson("/api/v1/patients/{$patient->id}/medical-history")
        ->assertForbidden();

    $this->actingAs($receptionist)
        ->postJson("/api/v1/patients/{$patient->id}/medical-history", [
            'category' => PatientMedicalHistory::CATEGORY_CONDITION,
            'term' => 'Asthma',
        ])
        ->assertForbidden();

    $this->actingAs($receptionist)
        ->postJson("/api/v1/patients/{$patient->id}/medication-reconciliations", [
            'medicine_name' => 'Metformin 500mg',
        ])
        ->assertForbidden();
});

test('medication reconciliation can be recorded and listed by a clinician', function () {
    $patient = Patient::factory()->create();
    $doctor = crd_doctor();

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/medication-reconciliations", [
            'medicine_name' => 'Metformin 500mg',
            'dosage' => '500 mg',
            'frequency' => 'twice daily',
            'source' => MedicationReconciliation::SOURCE_CLINICIAN,
            'notes' => 'Confirmed at last pharmacy visit.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.medicine_name', 'Metformin 500mg')
        ->assertJsonPath('data.source', MedicationReconciliation::SOURCE_CLINICIAN)
        ->assertJsonPath('data.reconciled_by', $doctor->id);

    $this->actingAs($doctor)
        ->getJson("/api/v1/patients/{$patient->id}/medication-reconciliations")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('active reconciled medications feed the clinical decision support evaluation', function () {
    $patient = Patient::factory()->create();
    $doctor = crd_doctor();
    $allergen = Allergen::factory()->create([
        'name' => 'Penicillin',
        'family' => 'penicillin',
        'aliases' => ['amoxicillin', 'ampicillin', 'penicillin'],
    ]);

    PatientMedicalHistory::factory()->allergyTo($allergen)->create([
        'patient_id' => $patient->id,
    ]);

    MedicationReconciliation::factory()->medicine('Amoxicillin 500mg')->create([
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate", [
            'medicines' => ['Amoxicillin 250mg'],
        ])
        ->assertCreated()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.trigger_type', 'allergy_warning')
        ->assertJsonPath('data.0.recommendation', fn ($v) => str_contains($v, 'penicillin family'));
});

test('allergy notes scan reports patients who would lose coverage without structured history', function () {
    $doctor = crd_doctor();
    $uncovered = Patient::factory()->create();
    $covered = Patient::factory()->create();

    $allergen = Allergen::factory()->create([
        'name' => 'Penicillin',
        'family' => 'penicillin',
        'aliases' => ['amoxicillin', 'ampicillin', 'penicillin'],
    ]);

    PatientMedicalHistory::factory()->allergyTo($allergen)->create([
        'patient_id' => $covered->id,
    ]);

    Prescription::factory()->create([
        'patient_id' => $uncovered->id,
        'doctor_id' => $doctor->id,
        'status' => 'pending',
        'notes' => 'Patient has a documented allergy to penicillin family.',
    ]);

    Prescription::factory()->create([
        'patient_id' => $covered->id,
        'doctor_id' => $doctor->id,
        'status' => 'dispensed',
        'notes' => 'Allergic rash to penicillin observed.',
    ]);

    Prescription::factory()->create([
        'patient_id' => Patient::factory()->create()->id,
        'doctor_id' => $doctor->id,
        'status' => 'pending',
        'notes' => 'Routine penicillin course; recheck in two weeks.',
    ]);

    $findings = (new ScanAllergyNotesCommand)->findings();

    expect(collect($findings)->pluck('patient_id')->all())->toBe([$uncovered->id, $covered->id])
        ->and($findings[0]['has_structured_coverage'])->toBeFalse()
        ->and($findings[1]['has_structured_coverage'])->toBeTrue()
        ->and($findings[0]['family'])->toBe('penicillin');
});

test('clinical reference migrations are reversible', function () {
    $tables = ['allergens', 'drug_interaction_rules', 'dosage_rules', 'patient_medical_histories', 'medication_reconciliations'];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    Artisan::call('migrate:rollback', ['--step' => 5]);

    // Keep rolling back until the clinical reference tables are gone so the
    // assertion is robust to unrelated migrations (e.g. clinical-record fields)
    // that may be created after the reference data batch.
    $guard = 0;
    while (Schema::hasTable($tables[0]) && $guard < 20) {
        Artisan::call('migrate:rollback', ['--step' => 1]);
        $guard++;
    }

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse("$table should be dropped by rollback");
    }

    Artisan::call('migrate');

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("$table should be re-created by migrate");
    }
});
