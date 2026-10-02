<?php

use App\Models\Branch;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\MedicalDocument;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Services\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

pest()->use(RefreshDatabase::class);

/**
 * BranchContext keeps request-scoped state in statics that survive between
 * sequential tests in the same process; reset it so every test starts with a
 * clean tenant context (mirroring ResolveBranch per real request).
 */
beforeEach(function (): void {
    BranchContext::reset();
});

function idor_branch(): Branch
{
    return Branch::factory()->create();
}

function idor_user(string $role, Branch $branch): User
{
    return User::factory()->create(['role' => $role, 'branch_id' => $branch->id]);
}

function idor_patient(Branch $branch): Patient
{
    return Patient::factory()->create(['branch_id' => $branch->id]);
}

function idor_patient_user(Patient $patient): User
{
    return User::factory()->create(['role' => 'Patient', 'patient_id' => $patient->id]);
}

test('a doctor can read an encounter of its own branch but not of another branch', function () {
    [$doctorA, $encounterA, $encounterB] = (function (): array {
        $branchA = idor_branch();
        $branchB = idor_branch();
        $doctorA = idor_user('Doctor', $branchA);

        $encounterA = Encounter::factory()->create(['patient_id' => idor_patient($branchA)->id]);
        $encounterB = Encounter::factory()->create(['patient_id' => idor_patient($branchB)->id]);

        return [$doctorA, $encounterA, $encounterB];
    })();

    $this->actingAs($doctorA)
        ->getJson("/api/v1/encounters/{$encounterA->id}")
        ->assertOk();

    $this->actingAs($doctorA)
        ->getJson("/api/v1/encounters/{$encounterB->id}")
        ->assertNotFound();
});

test('a doctor cannot finalize an encounter of another branch', function () {
    $branchA = idor_branch();
    $branchB = idor_branch();
    $doctorA = idor_user('Doctor', $branchA);
    $encounterB = Encounter::factory()->create(['patient_id' => idor_patient($branchB)->id]);

    $this->actingAs($doctorA)
        ->postJson("/api/v1/encounters/{$encounterB->id}/finalize", [])
        ->assertNotFound();
});

test('a pharmacist cannot download a prescription of another branch', function () {
    $branchA = idor_branch();
    $branchB = idor_branch();
    $pharmacistA = idor_user('Pharmacist', $branchA);
    $prescriptionB = Prescription::factory()->create(['patient_id' => idor_patient($branchB)->id]);

    $this->actingAs($pharmacistA)
        ->getJson("/api/v1/prescriptions/{$prescriptionB->id}/pdf")
        ->assertNotFound();
});

test('an accountant cannot download an invoice of another branch', function () {
    $branchA = idor_branch();
    $branchB = idor_branch();
    $accountantA = idor_user('Accountant', $branchA);
    $invoiceB = Invoice::factory()->create([
        'patient_id' => idor_patient($branchB)->id,
        'branch_id' => $branchB->id,
    ]);

    $this->actingAs($accountantA)
        ->getJson("/api/v1/invoices/{$invoiceB->id}/pdf")
        ->assertNotFound();
});

test('a doctor cannot update a lab order of another branch', function () {
    $branchA = idor_branch();
    $branchB = idor_branch();
    $doctorA = idor_user('Doctor', $branchA);
    $labOrderB = LabOrder::factory()->create([
        'patient_id' => idor_patient($branchB)->id,
        'branch_id' => $branchB->id,
    ]);

    $this->actingAs($doctorA)
        ->putJson("/api/v1/lab-orders/{$labOrderB->id}", ['test_name' => 'Hijacked Test'])
        ->assertNotFound();
});

test('a user without the patients.view permission cannot read another patient by id', function () {
    $branch = idor_branch();
    $pharmacist = idor_user('Pharmacist', $branch);
    $patient = idor_patient($branch);

    $this->actingAs($pharmacist)
        ->getJson("/api/v1/patients/{$patient->id}")
        ->assertForbidden();
});

test('a portal patient cannot download another patient invoice pdf', function () {
    [$userA, $invoiceB] = (function (): array {
        $patientA = Patient::factory()->create();
        $invoiceB = Invoice::factory()->create(['patient_id' => Patient::factory()->create()->id]);

        return [idor_patient_user($patientA), $invoiceB];
    })();

    $this->actingAs($userA)
        ->getJson("/api/v1/portal/invoices/{$invoiceB->id}/pdf")
        ->assertForbidden();
});

test('a portal patient cannot download another patient prescription pdf', function () {
    [$userA, $prescriptionB] = (function (): array {
        $patientA = Patient::factory()->create();
        $prescriptionB = Prescription::factory()->create(['patient_id' => Patient::factory()->create()->id]);

        return [idor_patient_user($patientA), $prescriptionB];
    })();

    $this->actingAs($userA)
        ->getJson("/api/v1/portal/prescriptions/{$prescriptionB->id}/pdf")
        ->assertForbidden();
});

test('a portal patient cannot download another patient medical document', function () {
    [$userA, $documentB] = (function (): array {
        $patientA = Patient::factory()->create();
        $documentB = MedicalDocument::factory()->create(['patient_id' => Patient::factory()->create()->id]);

        return [idor_patient_user($patientA), $documentB];
    })();

    $this->actingAs($userA)
        ->getJson("/api/v1/portal/documents/{$documentB->id}/download")
        ->assertForbidden();
});

test('the portal lists only the signed-in patient invoices prescriptions and lab results', function () {
    $patientA = Patient::factory()->create();
    $userA = idor_patient_user($patientA);
    $patientB = Patient::factory()->create();

    Invoice::factory()->count(2)->create(['patient_id' => $patientB->id]);
    Prescription::factory()->count(2)->create(['patient_id' => $patientB->id]);
    LabOrder::factory()->count(2)->create(['patient_id' => $patientB->id]);
    MedicalDocument::factory()->count(2)->create(['patient_id' => $patientB->id]);

    $this->actingAs($userA)
        ->getJson('/api/v1/portal/invoices')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($userA)
        ->getJson('/api/v1/portal/prescriptions')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($userA)
        ->getJson('/api/v1/portal/lab-results')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($userA)
        ->getJson('/api/v1/portal/documents')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('a portal patient cannot list or upload documents for another patient through staff routes', function () {
    $patientA = Patient::factory()->create();
    $userA = idor_patient_user($patientA);
    $patientB = Patient::factory()->create();

    $this->actingAs($userA)
        ->getJson("/api/v1/patients/{$patientB->id}/documents")
        ->assertForbidden();

    $this->actingAs($userA)
        ->postJson("/api/v1/patients/{$patientB->id}/documents", [
            'category' => 'test_result',
            'file' => UploadedFile::fake()->create('mydoc.pdf', 10),
        ])
        ->assertForbidden();

    expect(MedicalDocument::where('patient_id', $patientB->id)->count())->toBe(0);
});
