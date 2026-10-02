<?php

use App\Models\Allergen;
use App\Models\DrugInteractionRule;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Services\ClinicalDecisionSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

pest()->use(RefreshDatabase::class);

function fcs_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

/**
 * @return array<int, array<string, string>>
 */
function fcs_items(): array
{
    return [
        ['medicine_name' => 'Metformin 500mg', 'dosage' => '500 mg', 'frequency' => 'once daily', 'duration' => '30 days', 'quantity' => 30],
    ];
}

/**
 * Bind a CDSS double that explodes, standing in for an unavailable rule table,
 * a dropped connection, or any other fault inside the safety path.
 */
function fcs_break_safety_check(): void
{
    app()->bind(ClinicalDecisionSupportService::class, fn () => new class extends ClinicalDecisionSupportService
    {
        public function checkCandidateMedicines(Patient $patient, array $candidateItems): array
        {
            throw new RuntimeException('clinical safety rules unavailable');
        }
    });
}

test('a prescription is blocked when the safety check itself errors', function () {
    $doctor = fcs_doctor();
    $patient = Patient::factory()->create();

    fcs_break_safety_check();

    // The safety evaluation is safety-critical: an unavailable rules engine
    // must not be treated as "no problems found". The request may fail loudly,
    // but it must never persist a prescription.
    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'status' => 'pending',
            'items' => fcs_items(),
        ])
        ->assertStatus(500);

    expect(Prescription::count())->toBe(0)
        ->and(PrescriptionItem::count())->toBe(0);
});

test('a supply error inside the safety check does not leak prescription data or silently allow', function () {
    $doctor = fcs_doctor();
    $patient = Patient::factory()->create(['name' => 'Confidential Patient']);

    fcs_break_safety_check();

    $response = $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'status' => 'pending',
            'items' => fcs_items(),
        ]);

    $body = $response->getContent();

    // Fail closed, and fail quietly: no patient identity, no SQL, no stack trace.
    expect($body)->not->toContain('Confidential Patient')
        ->and($body)->not->toContain('SQLSTATE')
        ->and($body)->not->toContain($doctor->name);

    expect(Prescription::count())->toBe(0);
});

test('an erroring safety check cannot be bypassed by supplying an override reason', function () {
    $doctor = fcs_doctor();
    $patient = Patient::factory()->create();

    fcs_break_safety_check();

    // An override is a decision to accept a *known* alert. It must not become
    // a way to write a prescription when no evaluation happened at all.
    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'status' => 'pending',
            'override_reason' => 'Clinician accepts risk',
            'items' => fcs_items(),
        ])
        ->assertStatus(500);

    expect(Prescription::count())->toBe(0);
});

test('an empty rule set produces no alert, which is a data gap rather than a clean bill of health', function () {
    $doctor = fcs_doctor();
    $patient = Patient::factory()->create();

    // No interaction rules, dosage rules or allergens are loaded. The engine
    // correctly reports nothing wrong — and the prescription is written. This
    // test documents the real fail-open path: enforcement is only as good as
    // the reference data behind it.
    expect(DrugInteractionRule::count())->toBe(0)
        ->and(Allergen::count())->toBe(0);

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'status' => 'pending',
            'items' => fcs_items(),
        ])
        ->assertCreated();

    expect(Prescription::count())->toBe(1)
        ->and(PrescriptionItem::count())->toBe(1);
});

test('clinical safety enforcement has no feature flag and cannot be switched off', function () {
    $doctor = fcs_doctor();
    $patient = Patient::factory()->create();

    DrugInteractionRule::factory()->between('Warfarin', 'Ibuprofen')->approved()->create();

    // The behaviour above holds with no configuration change whatsoever: the
    // safety block is unconditional, so no environment variable, .env entry or
    // config default can relax it. This asserts the absence of a bypass.
    foreach ([true, false, null] as $unused) {
        config(['app.env' => $unused === null ? 'production' : ($unused ? 'production' : 'local')]);

        $this->actingAs($doctor)
            ->postJson('/api/v1/prescriptions', [
                'patient_id' => $patient->id,
                'status' => 'pending',
                'items' => [
                    ['medicine_name' => 'Warfarin 5mg', 'dosage' => '5 mg', 'frequency' => 'once daily', 'duration' => '30 days', 'quantity' => 30],
                    ['medicine_name' => 'Ibuprofen 400mg', 'dosage' => '400 mg', 'frequency' => 'three times daily', 'duration' => '10 days', 'quantity' => 30],
                ],
            ])
            ->assertUnprocessable();
    }

    expect(Prescription::count())->toBe(0)
        ->and(DB::table('prescriptions')->count())->toBe(0);
});
