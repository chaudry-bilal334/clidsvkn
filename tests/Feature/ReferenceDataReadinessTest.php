<?php

use App\Models\Allergen;
use App\Models\DosageRule;
use App\Models\DrugInteractionRule;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Services\ReferenceDataReadinessService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function rdr_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

/**
 * @return array<int, array<string, string>>
 */
function rdr_items(): array
{
    return [
        ['medicine_name' => 'Metformin 500mg', 'dosage' => '500 mg', 'frequency' => 'once daily', 'duration' => '30 days', 'quantity' => 30],
    ];
}

/**
 * @return array<string, mixed>
 */
function rdr_prescription_payload(int $patientId): array
{
    return [
        'patient_id' => $patientId,
        'status' => 'pending',
        'items' => rdr_items(),
    ];
}

/**
 * Approve one row in every required rule set, i.e. a loaded ruleset.
 */
function rdr_seed_ready_ruleset(): void
{
    Allergen::factory()->create(['name' => 'Penicillin', 'is_approved' => true]);
    DosageRule::factory()->create(['medicine_name' => 'Metformin', 'max_daily_mg' => 2000, 'is_approved' => true]);
    DrugInteractionRule::factory()->between('Warfarin', 'Ibuprofen')->approved()->create();
}

test('an empty ruleset blocks prescription creation in production', function () {
    $doctor = rdr_doctor();
    $patient = Patient::factory()->create();

    config(['clinic.reference_data.require_approved_before_clinical_writes' => true]);

    expect(app(ReferenceDataReadinessService::class)->enforcementEnabled())->toBeTrue()
        ->and(app(ReferenceDataReadinessService::class)->isReady())->toBeFalse();

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', rdr_prescription_payload($patient->id))
        ->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.reference_data.ready', false)
        ->assertJsonPath('errors.reference_data.missing', ['allergens', 'dosage_rules', 'drug_interaction_rules']);

    expect(Prescription::count())->toBe(0)
        ->and(PrescriptionItem::count())->toBe(0);
});

test('the production refusal names the missing rule sets and the fix', function () {
    $doctor = rdr_doctor();
    $patient = Patient::factory()->create();

    config(['clinic.reference_data.require_approved_before_clinical_writes' => true]);

    $response = $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', rdr_prescription_payload($patient->id))
        ->assertStatus(503);

    $message = $response->json('message');

    // Actionable: says what is missing and what to do about it.
    expect($message)->toContain('allergens')
        ->and($message)->toContain('dosage rules')
        ->and($message)->toContain('drug interaction rules')
        ->and($message)->toContain('approved')
        ->and($message)->toContain('Prescribing is blocked');
});

test('a partially seeded ruleset still blocks in production and names only the gap', function () {
    $doctor = rdr_doctor();
    $patient = Patient::factory()->create();

    Allergen::factory()->create(['name' => 'Penicillin', 'is_approved' => true]);
    DosageRule::factory()->create(['medicine_name' => 'Metformin', 'is_approved' => true]);

    config(['clinic.reference_data.require_approved_before_clinical_writes' => true]);

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', rdr_prescription_payload($patient->id))
        ->assertStatus(503)
        ->assertJsonPath('errors.reference_data.missing', ['drug_interaction_rules']);

    expect(Prescription::count())->toBe(0);
});

test('an unapproved ruleset does not count as loaded in production', function () {
    $doctor = rdr_doctor();
    $patient = Patient::factory()->create();

    // Rows exist but none are approved, so the engine would still be blind.
    Allergen::factory()->create(['name' => 'Penicillin', 'is_approved' => false]);
    DosageRule::factory()->create(['medicine_name' => 'Metformin', 'is_approved' => false]);
    DrugInteractionRule::factory()->between('Warfarin', 'Ibuprofen')->create();

    config(['clinic.reference_data.require_approved_before_clinical_writes' => true]);

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', rdr_prescription_payload($patient->id))
        ->assertStatus(503);

    expect(Prescription::count())->toBe(0);
});

test('a fully seeded ruleset allows prescription creation when enforcement is on', function () {
    $doctor = rdr_doctor();
    $patient = Patient::factory()->create();

    rdr_seed_ready_ruleset();

    config(['clinic.reference_data.require_approved_before_clinical_writes' => true]);

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', rdr_prescription_payload($patient->id))
        ->assertCreated();

    expect(Prescription::count())->toBe(1)
        ->and(PrescriptionItem::count())->toBe(1);
});

test('the config override forces enforcement on outside production', function () {
    $doctor = rdr_doctor();
    $patient = Patient::factory()->create();

    config(['clinic.reference_data.require_approved_before_clinical_writes' => true]);

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', rdr_prescription_payload($patient->id))
        ->assertStatus(503);

    expect(Prescription::count())->toBe(0);
});

test('the config override can be turned off outside production', function () {
    $doctor = rdr_doctor();
    $patient = Patient::factory()->create();

    config(['clinic.reference_data.require_approved_before_clinical_writes' => false]);

    expect(app(ReferenceDataReadinessService::class)->enforcementEnabled())->toBeFalse();

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', rdr_prescription_payload($patient->id))
        ->assertCreated();

    expect(Prescription::count())->toBe(1);
});

test('enforcement cannot be disabled while the app is in production', function () {
    // The production contract, asserted directly: whatever the config says,
    // production enforces. There is no configuration that weakens a live
    // clinic, so an operator cannot turn this off by accident or by mistake.
    expect(ReferenceDataReadinessService::resolveEnforcement(null, true))->toBeTrue()
        ->and(ReferenceDataReadinessService::resolveEnforcement(false, true))->toBeTrue()
        ->and(ReferenceDataReadinessService::resolveEnforcement(true, true))->toBeTrue();
});

test('outside production enforcement follows the config override', function () {
    expect(ReferenceDataReadinessService::resolveEnforcement(null, false))->toBeFalse()
        ->and(ReferenceDataReadinessService::resolveEnforcement(false, false))->toBeFalse()
        ->and(ReferenceDataReadinessService::resolveEnforcement(true, false))->toBeTrue();
});

test('an unseeded ruleset does not block when enforcement is off', function () {
    $doctor = rdr_doctor();
    $patient = Patient::factory()->create();

    expect(app(ReferenceDataReadinessService::class)->enforcementEnabled())->toBeFalse();

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', rdr_prescription_payload($patient->id))
        ->assertCreated();

    expect(Prescription::count())->toBe(1);
});

test('the readiness service reports each rule set separately', function () {
    Allergen::factory()->create(['name' => 'Penicillin', 'is_approved' => true]);

    $status = app(ReferenceDataReadinessService::class)->status();

    expect($status['ready'])->toBeFalse()
        ->and($status['types']['allergens'])->toMatchArray(['approved' => 1, 'ready' => true])
        ->and($status['types']['dosage_rules'])->toMatchArray(['approved' => 0, 'ready' => false])
        ->and($status['types']['drug_interaction_rules'])->toMatchArray(['approved' => 0, 'ready' => false]);
});

test('the readiness service counts total rows as well as approved ones', function () {
    Allergen::factory()->create(['name' => 'Penicillin', 'is_approved' => true]);
    Allergen::factory()->create(['name' => 'Latex', 'is_approved' => false]);

    $allergens = app(ReferenceDataReadinessService::class)->status()['types']['allergens'];

    expect($allergens['total'])->toBe(2)
        ->and($allergens['approved'])->toBe(1);
});

test('the admin readiness endpoint reports the state', function () {
    $admin = User::factory()->create(['role' => 'Admin']);

    Allergen::factory()->create(['name' => 'Penicillin', 'is_approved' => true]);

    $this->actingAs($admin)
        ->getJson('/api/v1/reference-data/readiness')
        ->assertOk()
        ->assertJsonPath('data.ready', false)
        ->assertJsonPath('data.enforcement_enabled', false)
        ->assertJsonPath('data.missing', ['dosage_rules', 'drug_interaction_rules'])
        ->assertJsonPath('data.types.allergens.approved', 1);
});

test('the health endpoint reports an unready ruleset as unhealthy when enforcement is on', function () {
    config(['clinic.reference_data.require_approved_before_clinical_writes' => true]);

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unhealthy')
        ->assertJsonPath('checks.clinical_reference_data.status', 'unhealthy')
        ->assertJsonPath('checks.clinical_reference_data.ready', false)
        ->assertJsonPath('checks.clinical_reference_data.enforcement_enabled', true);
});

test('the health endpoint reports a ready ruleset as healthy when enforcement is on', function () {
    rdr_seed_ready_ruleset();

    config(['clinic.reference_data.require_approved_before_clinical_writes' => true]);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('checks.clinical_reference_data.status', 'healthy')
        ->assertJsonPath('checks.clinical_reference_data.ready', true);
});

test('the health endpoint reports an unready ruleset as merely degraded outside production', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('checks.clinical_reference_data.status', 'degraded')
        ->assertJsonPath('checks.clinical_reference_data.enforcement_enabled', false);
});

test('the health endpoint names the missing rule sets for an operator', function () {
    config(['clinic.reference_data.require_approved_before_clinical_writes' => true]);

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('checks.clinical_reference_data.message', fn ($message) => str_contains($message, 'allergens')
            && str_contains($message, 'dosage rules')
            && str_contains($message, 'Prescribing is blocked'));
});

test('a genuine production environment refuses to prescribe against an empty ruleset', function () {
    $doctor = rdr_doctor();
    $patient = Patient::factory()->create();

    // Flip the real application environment rather than the config override.
    // The prescriptions route runs the `web` middleware group, whose CSRF
    // check is only bypassed while runningUnitTests() is true, so forgery
    // middleware is disabled explicitly for this one request.
    app()->instance('env', 'production');

    expect(app()->isProduction())->toBeTrue()
        ->and(app(ReferenceDataReadinessService::class)->enforcementEnabled())->toBeTrue();

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', rdr_prescription_payload($patient->id))
        ->assertStatus(503);

    expect(Prescription::count())->toBe(0);
});

test('a genuine production environment prescribes normally once the ruleset is loaded', function () {
    $doctor = rdr_doctor();
    $patient = Patient::factory()->create();

    rdr_seed_ready_ruleset();

    app()->instance('env', 'production');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', rdr_prescription_payload($patient->id))
        ->assertCreated();

    expect(Prescription::count())->toBe(1)
        ->and(PrescriptionItem::count())->toBe(1);
});
