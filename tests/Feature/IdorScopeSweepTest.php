<?php

use App\Models\Branch;
use App\Models\Claim;
use App\Models\ClinicalDecisionSupport;
use App\Models\Consent;
use App\Models\DataExportRequest;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\MedicationReconciliation;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\PatientMedicalHistory;
use App\Models\Prescription;
use App\Models\QueueEntry;
use App\Models\TelehealthSession;
use App\Models\User;
use App\Services\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

/**
 * Branch-scope sweep: the G11 audit flagged patient/queue records that carried
 * tenant identity but had no model scope, so cross-branch staff could read or
 * mutate them by guessing an ID. This file proves the scopes that closed those
 * gaps. BranchContext keeps request-scoped state in statics that survive
 * between sequential tests; reset it so every test starts clean.
 */
beforeEach(function (): void {
    BranchContext::reset();
});

/**
 * @return array{0: Branch, 1: Branch, 2: User, 3: Patient, 4: Patient}
 */
function sweep_world(): array
{
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();

    $doctorA = User::factory()->create(['role' => 'Doctor', 'branch_id' => $branchA->id]);
    $patientA = Patient::factory()->create(['branch_id' => $branchA->id]);
    $patientB = Patient::factory()->create(['branch_id' => $branchB->id]);

    return [$branchA, $branchB, $doctorA, $patientA, $patientB];
}

test('a doctor cannot update another branch patient medical history by id', function () {
    [, , $doctorA, , $patientB] = sweep_world();

    $historyB = PatientMedicalHistory::factory()->create(['patient_id' => $patientB->id]);

    $this->actingAs($doctorA)
        ->putJson("/api/v1/medical-history/{$historyB->id}", ['term' => 'Hijacked by cross-branch staff'])
        ->assertNotFound();
});

test('a doctor cannot transition another branch queue entry by id', function () {
    [, , $doctorA, , $patientB] = sweep_world();

    $entryB = QueueEntry::factory()->create([
        'patient_id' => $patientB->id,
        'branch_id' => $patientB->branch_id,
    ]);

    $this->actingAs($doctorA)
        ->putJson("/api/v1/queue/{$entryB->id}", ['status' => QueueEntry::STATUS_WAITING])
        ->assertNotFound();

    // And the index never surfaces the other branch's entry.
    $this->actingAs($doctorA)
        ->getJson('/api/v1/queue')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('queue entries that belong to another branch are hidden from the index', function () {
    [, , $doctorA, $patientA, $patientB] = sweep_world();

    QueueEntry::factory()->create(['patient_id' => $patientA->id, 'branch_id' => $patientA->branch_id]);
    QueueEntry::factory()->create(['patient_id' => $patientB->id, 'branch_id' => $patientB->branch_id]);

    $this->actingAs($doctorA)
        ->getJson('/api/v1/queue')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

/**
 * The patient-anchored models that had no direct admin route (most records are
 * exposed through scoped parents) are still protected at the query layer: with
 * branch A active, a branch-B record is unreadable, while bypassing (Admin or
 * cross-branch staff) restores visibility.
 */
$sweepModels = [
    Consent::class,
    PatientAllergy::class,
    Claim::class,
    MedicationReconciliation::class,
    TelehealthSession::class,
    ClinicalDecisionSupport::class,
    DataExportRequest::class,
    PatientMedicalHistory::class,
    QueueEntry::class,
];

test('patient-anchored records of another branch are invisible to a branch-scoped query', function (string $modelClass) {
    [$branchA, , , , $patientB] = sweep_world();

    $rowB = $modelClass::factory()->create(['patient_id' => $patientB->id]);

    BranchContext::set((int) $branchA->id);

    expect($modelClass::find($rowB->id))->toBeNull()
        ->and($modelClass::query()->count())->toBe(0);

    // The bypass path (Admin / is_cross_branch without an explicit branch)
    // still sees the row — scope, not a broken query, is what hides it.
    BranchContext::bypass(true);

    expect($modelClass::find($rowB->id))->not->toBeNull();
})->with($sweepModels);

/**
 * The G11 leftover leaf models have no direct by-ID routes — they are reached
 * only through scoped parents. The two exceptions (top-level create/index on
 * dispensings and inventory transactions) still route through the branch-scoped
 * parent record; these tests prove the partitioning holds there too.
 */
test('a doctor cannot see another branch inventory transactions in the list', function () {
    [$branchA, $branchB, $doctorA] = sweep_world();

    $itemA = InventoryItem::factory()->create(['branch_id' => $branchA->id]);
    $itemB = InventoryItem::factory()->create(['branch_id' => $branchB->id]);

    InventoryTransaction::factory()->create(['inventory_item_id' => $itemA->id, 'quantity' => 10]);
    InventoryTransaction::factory()->create(['inventory_item_id' => $itemB->id, 'quantity' => 5]);

    $this->actingAs($doctorA)
        ->getJson('/api/v1/inventory/transactions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.item_name', $itemA->item_name);
});

test('a pharmacist can dispense their own branch prescription', function () {
    [$branchA, , , $patientA] = sweep_world();

    $pharmacistA = User::factory()->create(['role' => 'Pharmacist', 'branch_id' => $branchA->id]);
    $prescriptionA = Prescription::factory()->create(['patient_id' => $patientA->id, 'status' => 'pending']);

    $item = InventoryItem::factory()->create([
        'branch_id' => $branchA->id,
        'item_name' => 'Paracetamol 500mg Tablets',
        'stock_quantity' => 0,
        'reorder_level' => 5,
    ]);

    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-SWEEP',
        'expiry_date' => now()->addMonths(12)->toDateString(),
        'initial_quantity' => 20,
        'current_quantity' => 20,
    ]);

    $this->actingAs($pharmacistA)
        ->postJson('/api/v1/dispensings', [
            'prescription_id' => $prescriptionA->id,
            'items' => [
                ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 5],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'dispensed');
});

test('a pharmacist cannot dispense against another branch prescription', function () {
    [$branchA, , , , $patientB] = sweep_world();

    $pharmacistA = User::factory()->create(['role' => 'Pharmacist', 'branch_id' => $branchA->id]);
    $prescriptionB = Prescription::factory()->create(['patient_id' => $patientB->id]);

    $this->actingAs($pharmacistA)
        ->postJson('/api/v1/dispensings', [
            'prescription_id' => $prescriptionB->id,
            'items' => [
                ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 5],
            ],
        ])
        ->assertNotFound();
});
