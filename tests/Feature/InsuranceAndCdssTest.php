<?php

use App\Models\Allergen;
use App\Models\Claim;
use App\Models\ClinicalDecisionSupport;
use App\Models\DosageRule;
use App\Models\DrugInteractionRule;
use App\Models\InsuranceProvider;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function ic_admin(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

function ic_accountant(): User
{
    return User::factory()->create(['role' => 'Accountant']);
}

function ic_receptionist(): User
{
    return User::factory()->create(['role' => 'Receptionist']);
}

function ic_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function ic_pharmacist(): User
{
    return User::factory()->create(['role' => 'Pharmacist']);
}

function ic_patient(): User
{
    $patient = Patient::factory()->create();

    return User::factory()->create([
        'role' => 'Patient',
        'patient_id' => $patient->id,
    ]);
}

function ic_provider(): InsuranceProvider
{
    return InsuranceProvider::factory()->create(['is_active' => true]);
}

function ic_unpaidInvoice(Patient $patient): Invoice
{
    return Invoice::factory()->create([
        'patient_id' => $patient->id,
        'status' => 'generated',
        'balance_due' => 500.00,
        'total' => 500.00,
    ]);
}

function ic_paidInvoice(Patient $patient): Invoice
{
    return Invoice::factory()->create([
        'patient_id' => $patient->id,
        'status' => 'paid',
        'balance_due' => 0.00,
        'total' => 500.00,
        'amount_paid' => 500.00,
    ]);
}

function ic_draftClaim(Patient $patient, Invoice $invoice, InsuranceProvider $provider): Claim
{
    return Claim::factory()->create([
        'patient_id' => $patient->id,
        'invoice_id' => $invoice->id,
        'insurance_provider_id' => $provider->id,
        'claimed_amount' => $invoice->total,
        'status' => Claim::STATUS_DRAFT,
    ]);
}

function ic_prescriptionWithItems(
    Patient $patient,
    User $doctor,
    array $items,
    string $status = 'pending',
): Prescription {
    $prescription = Prescription::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => $status,
        'notes' => 'Recheck in two weeks.',
    ]);

    foreach ($items as $item) {
        PrescriptionItem::create(array_merge(
            ['prescription_id' => $prescription->id],
            $item,
        ));
    }

    return $prescription;
}

function ic_interactionRule(string $medicineA, string $medicineB, ?string $guidance = null): DrugInteractionRule
{
    return DrugInteractionRule::factory()->between($medicineA, $medicineB)->create([
        'guidance' => $guidance,
    ]);
}

function ic_dosageRule(string $medicineName, float|int $maxDailyMg): DosageRule
{
    return DosageRule::factory()->forMedicine($medicineName, $maxDailyMg)->create();
}

function ic_allergen(string $name = 'Penicillin', string $family = 'penicillin', array $aliases = ['amoxicillin']): Allergen
{
    return Allergen::factory()->create([
        'name' => $name,
        'family' => $family,
        'aliases' => $aliases,
    ]);
}

test('claim lifecycle can progress from draft through paid', function () {
    $provider = ic_provider();
    $patient = Patient::factory()->create();
    $invoice = ic_unpaidInvoice($patient);
    $claim = ic_draftClaim($patient, $invoice, $provider);

    $user = ic_accountant();

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claim->id}/status", ['status' => 'submitted'])
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted');

    $claim->refresh();
    expect($claim->status)->toBe('submitted');

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claim->id}/status", [
            'status' => 'approved',
            'approved_amount' => $claim->claimed_amount,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approved_amount', 500);

    $claim->refresh();
    expect($claim->approved_amount)->toBe($claim->claimed_amount);

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claim->id}/status", ['status' => 'paid'])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    $claim->refresh();
    expect($claim->status)->toBe('paid');
});

test('claim cannot be submitted with inactive insurance provider', function () {
    $provider = InsuranceProvider::factory()->create(['is_active' => false]);
    $patient = Patient::factory()->create();
    $invoice = ic_unpaidInvoice($patient);
    $claim = ic_draftClaim($patient, $invoice, $provider);

    $this->actingAs(ic_accountant())
        ->patchJson("/api/v1/claims/{$claim->id}/status", ['status' => 'submitted'])
        ->assertUnprocessable();
});

test('claim amount cannot exceed invoice outstanding balance', function () {
    $provider = ic_provider();
    $patient = Patient::factory()->create();
    $invoice = ic_unpaidInvoice($patient);

    $this->actingAs(ic_accountant())
        ->postJson('/api/v1/claims', [
            'patient_id' => $patient->id,
            'invoice_id' => $invoice->id,
            'insurance_provider_id' => $provider->id,
            'policy_number' => 'POL-12345',
            'claimed_amount' => 600.00,
        ])
        ->assertUnprocessable();
});

test('claim cannot be created against a paid invoice', function () {
    $provider = ic_provider();
    $patient = Patient::factory()->create();
    $invoice = ic_paidInvoice($patient);

    $this->actingAs(ic_accountant())
        ->postJson('/api/v1/claims', [
            'patient_id' => $patient->id,
            'invoice_id' => $invoice->id,
            'insurance_provider_id' => $provider->id,
            'policy_number' => 'POL-12345',
            'claimed_amount' => 100.00,
        ])
        ->assertUnprocessable();
});

test('approved amount cannot exceed claimed amount', function () {
    $provider = ic_provider();
    $patient = Patient::factory()->create();
    $invoice = ic_unpaidInvoice($patient);
    $claim = ic_draftClaim($patient, $invoice, $provider);

    $this->actingAs(ic_accountant())
        ->patchJson("/api/v1/claims/{$claim->id}/status", [
            'status' => 'approved',
            'approved_amount' => $claim->claimed_amount + 100,
        ])
        ->assertUnprocessable();
});

test('combined approved claims cannot exceed invoice balance', function () {
    $provider = ic_provider();
    $patient = Patient::factory()->create();
    $invoice = ic_unpaidInvoice($patient);

    $claimA = ic_draftClaim($patient, $invoice, $provider);
    $claimA->claimed_amount = 400.00;
    $claimA->save();

    $claimB = ic_draftClaim($patient, $invoice, $provider);
    $claimB->claimed_amount = 200.00;
    $claimB->save();

    $user = ic_accountant();

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claimA->id}/status", [
            'status' => 'submitted',
        ])->assertOk();

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claimA->id}/status", [
            'status' => 'approved',
            'approved_amount' => 400.00,
        ])->assertOk();

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claimB->id}/status", [
            'status' => 'submitted',
        ])->assertOk();

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claimB->id}/status", [
            'status' => 'approved',
            'approved_amount' => 200.00,
        ])->assertUnprocessable();

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claimB->id}/status", [
            'status' => 'approved',
            'approved_amount' => 100.00,
        ])->assertOk();
});

test('claim rejection sets notes and reverts to draft on rework', function () {
    $provider = ic_provider();
    $patient = Patient::factory()->create();
    $invoice = ic_unpaidInvoice($patient);
    $claim = ic_draftClaim($patient, $invoice, $provider);

    $user = ic_accountant();

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claim->id}/status", [
            'status' => 'submitted',
        ])->assertOk();

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claim->id}/status", [
            'status' => 'rejected',
            'notes' => 'Missing documentation.',
        ])->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.notes', 'Missing documentation.');

    $claim->refresh();
    expect($claim->status)->toBe('rejected');

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claim->id}/status", ['status' => 'draft'])
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');
});

test('only draft claims can be edited', function () {
    $provider = ic_provider();
    $patient = Patient::factory()->create();
    $invoice = ic_unpaidInvoice($patient);
    $claim = ic_draftClaim($patient, $invoice, $provider);

    $user = ic_accountant();

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claim->id}/status", ['status' => 'submitted'])
        ->assertOk();

    $this->actingAs($user)
        ->putJson("/api/v1/claims/{$claim->id}", ['policy_number' => 'NEW-POL'])
        ->assertUnprocessable();
});

test('drug interaction warning generated for known interacting medications', function () {
    $patient = Patient::factory()->create();
    $doctor = ic_doctor();

    DrugInteractionRule::factory()->between('Warfarin', 'Ibuprofen')->approved()->create();

    ic_prescriptionWithItems($patient, $doctor, [
        ['medicine_name' => 'Warfarin 5mg', 'dosage' => '5 mg', 'frequency' => 'once daily', 'duration' => '30 days', 'quantity' => 30],
        ['medicine_name' => 'Ibuprofen 400mg', 'dosage' => '400 mg', 'frequency' => 'three times daily', 'duration' => '10 days', 'quantity' => 30],
    ]);

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate")
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.trigger_type', 'drug_interaction')
        ->assertJsonPath('data.0.recommendation', fn ($v) => ! str_contains($v, 'rule pending clinical review'));
});

test('dosage check flags excessive daily dose', function () {
    $patient = Patient::factory()->create();
    $doctor = ic_doctor();

    DosageRule::factory()->forMedicine('Ibuprofen', 2400)->approved()->create();

    ic_prescriptionWithItems($patient, $doctor, [
        ['medicine_name' => 'Ibuprofen 200mg', 'dosage' => '800 mg', 'frequency' => 'four times daily', 'duration' => '10 days', 'quantity' => 80],
    ]);

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate")
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.trigger_type', 'dosage_check')
        ->assertJsonPath('data.0.recommendation', fn ($v) => str_contains($v, 'exceeds the recommended maximum'));
});

test('allergy warning generated from structured patient medical history', function () {
    $patient = Patient::factory()->create();
    $doctor = ic_doctor();

    $allergen = Allergen::factory()->create([
        'name' => 'Penicillin',
        'family' => 'penicillin',
        'aliases' => ['amoxicillin', 'ampicillin', 'penicillin'],
    ]);

    PatientMedicalHistory::factory()->allergyTo($allergen)->create([
        'patient_id' => $patient->id,
    ]);

    ic_prescriptionWithItems($patient, $doctor, [
        ['medicine_name' => 'Amoxicillin 500mg', 'dosage' => '500 mg', 'frequency' => 'three times daily', 'duration' => '7 days', 'quantity' => 21],
    ], 'dispensed');

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate", [
            'medicines' => ['Amoxicillin 250mg'],
        ])
        ->assertCreated()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.trigger_type', 'allergy_warning')
        ->assertJsonPath('data.0.recommendation', fn ($v) => str_contains($v, 'penicillin family'));
});

test('duplicate alerts are not created on re-evaluation', function () {
    $patient = Patient::factory()->create();
    $doctor = ic_doctor();

    DrugInteractionRule::factory()->between('Warfarin', 'Aspirin')->approved()->create();

    ic_prescriptionWithItems($patient, $doctor, [
        ['medicine_name' => 'Warfarin 5mg', 'dosage' => '5 mg', 'frequency' => 'once daily', 'duration' => '30 days', 'quantity' => 30],
        ['medicine_name' => 'Aspirin 81mg', 'dosage' => '81 mg', 'frequency' => 'once daily', 'duration' => '30 days', 'quantity' => 30],
    ]);

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate")
        ->assertCreated()
        ->assertJsonCount(1, 'data');

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate")
        ->assertCreated()
        ->assertJsonCount(0, 'data');

    expect(ClinicalDecisionSupport::where('patient_id', $patient->id)->count())->toBe(1);
});

test('cdss alerts endpoint returns unacknowledged alerts for the current doctor', function () {
    $patient = Patient::factory()->create();
    $doctor = ic_doctor();
    $otherDoctor = ic_doctor();

    ClinicalDecisionSupport::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'trigger_type' => 'drug_interaction',
        'recommendation' => 'Test alert',
        'is_acknowledged' => false,
    ]);

    ClinicalDecisionSupport::factory()->acknowledged()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    ClinicalDecisionSupport::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $otherDoctor->id,
        'is_acknowledged' => false,
    ]);

    $this->actingAs($doctor)
        ->getJson('/api/v1/cdss/alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_acknowledged', false);
});

test('alert acknowledgment marks the alert as acknowledged', function () {
    $patient = Patient::factory()->create();
    $doctor = ic_doctor();

    $alert = ClinicalDecisionSupport::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'trigger_type' => 'dosage_check',
        'recommendation' => 'Check dose',
        'is_acknowledged' => false,
    ]);

    $this->actingAs($doctor)
        ->patchJson("/api/v1/cdss/alerts/{$alert->id}")
        ->assertOk()
        ->assertJsonPath('data.is_acknowledged', true);

    expect($alert->fresh()->is_acknowledged)->toBeTrue();
});

test('claim management restricted to admin, accountant, and receptionist', function () {
    $provider = ic_provider();
    $patient = Patient::factory()->create();
    $invoice = ic_unpaidInvoice($patient);
    $claim = ic_draftClaim($patient, $invoice, $provider);

    $this->actingAs(ic_doctor())
        ->postJson('/api/v1/claims', [
            'patient_id' => $patient->id,
            'invoice_id' => $invoice->id,
            'insurance_provider_id' => $provider->id,
            'policy_number' => 'POL-12345',
            'claimed_amount' => 100.00,
        ])
        ->assertForbidden();

    $this->actingAs(ic_pharmacist())
        ->postJson('/api/v1/claims', [
            'patient_id' => $patient->id,
            'invoice_id' => $invoice->id,
            'insurance_provider_id' => $provider->id,
            'policy_number' => 'POL-12345',
            'claimed_amount' => 100.00,
        ])
        ->assertForbidden();

    $this->actingAs(ic_patient())
        ->postJson('/api/v1/claims', [
            'patient_id' => $patient->id,
            'invoice_id' => $invoice->id,
            'insurance_provider_id' => $provider->id,
            'policy_number' => 'POL-12345',
            'claimed_amount' => 100.00,
        ])
        ->assertForbidden();

    foreach ([ic_admin(), ic_accountant(), ic_receptionist()] as $user) {
        $this->actingAs($user)
            ->postJson('/api/v1/claims', [
                'patient_id' => $patient->id,
                'invoice_id' => $invoice->id,
                'insurance_provider_id' => $provider->id,
                'policy_number' => 'POL-12345',
                'claimed_amount' => 100.00,
            ])
            ->assertCreated();
    }
});

test('doctors can view claims but not manage them', function () {
    $provider = ic_provider();
    $patient = Patient::factory()->create();
    $invoice = ic_unpaidInvoice($patient);
    $claim = ic_draftClaim($patient, $invoice, $provider);

    $this->actingAs(ic_doctor())
        ->getJson('/api/v1/claims')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs(ic_doctor())
        ->getJson("/api/v1/claims/{$claim->id}")
        ->assertOk();

    $this->actingAs(ic_doctor())
        ->patchJson("/api/v1/claims/{$claim->id}/status", ['status' => 'submitted'])
        ->assertForbidden();
});

test('cdss endpoints restricted to doctor and admin roles', function () {
    $patient = Patient::factory()->create();

    foreach ([ic_pharmacist(), ic_receptionist(), ic_accountant(), ic_patient()] as $user) {
        $this->actingAs($user)
            ->getJson('/api/v1/cdss/alerts')
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate")
            ->assertForbidden();
    }

    foreach ([ic_doctor(), ic_admin()] as $user) {
        $this->actingAs($user)
            ->getJson('/api/v1/cdss/alerts')
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate")
            ->assertCreated();
    }
});

test('claim list supports filtering by status and provider', function () {
    $provider = ic_provider();
    $patient = Patient::factory()->create();
    $invoice = ic_unpaidInvoice($patient);

    Claim::factory()->submitted()->create([
        'patient_id' => $patient->id,
        'invoice_id' => $invoice->id,
        'insurance_provider_id' => $provider->id,
    ]);

    Claim::factory()->approved()->create([
        'patient_id' => $patient->id,
        'invoice_id' => $invoice->id,
        'insurance_provider_id' => $provider->id,
    ]);

    $this->actingAs(ic_admin())
        ->getJson('/api/v1/claims?status=submitted')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'submitted');

    $this->actingAs(ic_admin())
        ->getJson("/api/v1/claims?insurance_provider_id={$provider->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('paid claim settles the invoice balance', function () {
    $provider = ic_provider();
    $patient = Patient::factory()->create();
    $invoice = ic_unpaidInvoice($patient);
    $claim = ic_draftClaim($patient, $invoice, $provider);
    $claim->claimed_amount = 300.00;
    $claim->save();

    $user = ic_accountant();

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claim->id}/status", ['status' => 'submitted'])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claim->id}/status", [
            'status' => 'approved',
            'approved_amount' => 300.00,
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson("/api/v1/claims/{$claim->id}/status", ['status' => 'paid'])
        ->assertOk();

    $invoice->refresh();
    expect((float) $invoice->amount_paid)->toBe(300.00)
        ->and((float) $invoice->balance_due)->toBe(200.00);
});

test('cdss evaluate accepts candidate medicines for pre-prescription checks', function () {
    $patient = Patient::factory()->create();
    $doctor = ic_doctor();

    DrugInteractionRule::factory()->between('Warfarin', 'Ibuprofen')->approved()->create();

    $this->actingAs($doctor)
        ->postJson("/api/v1/patients/{$patient->id}/cdss/evaluate", [
            'medicines' => ['Warfarin 5mg', 'Ibuprofen 400mg'],
        ])
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.trigger_type', 'drug_interaction');
});
