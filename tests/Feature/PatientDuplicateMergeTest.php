<?php

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Claim;
use App\Models\ClinicalDecisionSupport;
use App\Models\DataExportRequest;
use App\Models\Encounter;
use App\Models\Followup;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\MedicalDocument;
use App\Models\MedicationReconciliation;
use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use App\Models\Prescription;
use App\Models\QueueEntry;
use App\Models\TelehealthSession;
use App\Models\User;
use App\Services\PatientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

pest()->use(RefreshDatabase::class);

function dup_receptionist(): User
{
    return User::factory()->create(['role' => 'Receptionist']);
}

function dup_admin(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

function dup_patient(array $overrides = []): Patient
{
    return Patient::factory()->create($overrides);
}

/**
 * Seed one child record in every merge-reassignable table for a patient and
 * return the ACTUAL per-table row counts (some factories create related
 * records, e.g. ClaimFactory auto-creates an Invoice).
 *
 * @return array<string, int> table => row count owned by the patient
 */
function seed_child_records_for(Patient $patient): array
{
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

    Encounter::factory()->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'appointment_id' => $appointment->id]);
    Invoice::factory()->create(['patient_id' => $patient->id]);
    Prescription::factory()->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'appointment_id' => $appointment->id]);
    LabOrder::factory()->create(['patient_id' => $patient->id]);
    QueueEntry::factory()->create(['patient_id' => $patient->id, 'appointment_id' => $appointment->id]);
    MedicalDocument::factory()->create(['patient_id' => $patient->id]);
    TelehealthSession::factory()->create(['patient_id' => $patient->id]);
    Claim::factory()->create(['patient_id' => $patient->id]);
    ClinicalDecisionSupport::factory()->create(['patient_id' => $patient->id]);
    DataExportRequest::factory()->create(['patient_id' => $patient->id]);
    PatientMedicalHistory::factory()->create(['patient_id' => $patient->id]);
    MedicationReconciliation::factory()->create(['patient_id' => $patient->id]);
    Followup::factory()->create(['patient_id' => $patient->id]);

    $tables = [
        'appointments', 'encounters', 'invoices', 'prescriptions', 'lab_orders',
        'queue_entries', 'medical_documents', 'telehealth_sessions', 'claims',
        'clinical_decision_supports', 'data_export_requests',
        'patient_medical_histories', 'medication_reconciliations', 'followups',
    ];

    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = DB::table($table)->where('patient_id', $patient->id)->count();
    }

    return $counts;
}

/**
 * Assert the merge leaves no row pointing at the deleted duplicate, and that
 * every child count is preserved on the survivor.
 *
 * @param  array<string, int>  $expected
 */
function assert_no_orphaned_patient_rows(Patient $survivor, Patient $duplicate, array $expected): void
{
    $tableNames = array_keys($expected);

    foreach ($tableNames as $table) {
        $duplicateRows = DB::table((string) $table)->where('patient_id', $duplicate->id)->count();
        expect($duplicateRows)->toBe(0, "orphaned rows in {$table}");
    }

    foreach ($expected as $table => $count) {
        $survivorRows = DB::table((string) $table)->where('patient_id', $survivor->id)->count();
        expect($survivorRows)->toBe($count, "survivor row count mismatch in {$table}");
    }

    $duplicateRows = DB::table('users')->where('patient_id', $duplicate->id)->count();
    expect($duplicateRows)->toBe(0, 'orphaned rows in users');

    expect(Patient::find($duplicate->id))->toBeNull('duplicate patient row still present');
}

beforeEach(function (): void {
    $this->service = app(PatientService::class);
});

// ---------------------------------------------------------------------------
// Exact duplicate detection
// ---------------------------------------------------------------------------

it('flags an exact CNIC match as a possible duplicate', function () {
    $cnic = '42101-1234567-1';
    dup_patient(['cnic' => $cnic]);

    $matches = $this->service->findPossibleDuplicates([
        'name' => 'Different Name Entirely',
        'cnic' => $cnic,
    ]);

    expect($matches)->toHaveCount(1);
    expect($matches[0]['reasons'])->toContain('cnic');
    expect($matches[0]['reasons'])->toContain('exact');
    expect($matches[0]['score'])->toBeGreaterThanOrEqual(1.0);
});

it('flags an exact phone match as a possible duplicate despite name mismatch', function () {
    dup_patient(['phone' => '+1 (555) 234-1567']);

    $matches = $this->service->findPossibleDuplicates([
        'name' => 'Someone Else',
        'phone' => '+15552341567',
    ]);

    expect($matches)->toHaveCount(1);
    expect($matches[0]['reasons'])->toContain('phone');
});

it('flags an exact email match as a possible duplicate regardless of formatting', function () {
    dup_patient(['email' => 'John.Doe@Example.COM']);

    $matches = $this->service->findPossibleDuplicates([
        'name' => 'Unrelated Person',
        'email' => 'john.doe@example.com',
    ]);

    expect($matches)->toHaveCount(1);
    expect($matches[0]['reasons'])->toContain('email');
});

// ---------------------------------------------------------------------------
// Fuzzy duplicate detection
// ---------------------------------------------------------------------------

it('flags a Jaro-Winkler near-name match with matching DOB', function () {
    dup_patient(['name' => 'Mohammed Aslam', 'date_of_birth' => '1990-04-12']);

    $matches = $this->service->findPossibleDuplicates([
        'name' => 'Mohammad Aslam',
        'date_of_birth' => '1990-04-12',
    ]);

    expect($matches)->toHaveCount(1);
    expect($matches[0]['reasons'])->toContain('name');
    expect($matches[0]['score'])->toBeGreaterThanOrEqual(0.88);
});

it('returns no match for a weak name similarity with no corroboration', function () {
    dup_patient(['name' => 'Fatima Zahra', 'date_of_birth' => '1985-01-01']);

    $matches = $this->service->findPossibleDuplicates([
        'name' => 'Ali Raza',
        'date_of_birth' => '1999-09-09',
    ]);

    expect($matches)->toBeEmpty();
});

it('exact CNIC wins over a clashing name', function () {
    dup_patient(['name' => 'Real Owner', 'cnic' => '42101-1234567-1']);

    $matches = $this->service->findPossibleDuplicates([
        'name' => 'Different Person',
        'cnic' => '42101-1234567-1',
    ]);

    expect($matches)->toHaveCount(1);
    expect($matches[0]['patient']->name)->toBe('Real Owner');
    expect($matches[0]['score'])->toBeGreaterThanOrEqual(1.0);
});

// ---------------------------------------------------------------------------
// Registration (warn-and-confirm)
// ---------------------------------------------------------------------------

it('rejects a duplicate CNIC and hands back the record that already holds it', function () {
    $existing = dup_patient(['cnic' => '42101-1234567-1']);

    $response = $this->actingAs(dup_receptionist())
        ->postJson('/api/v1/patients', [
            'name' => 'New Person',
            'cnic' => '42101-1234567-1',
        ]);

    // `patients.cnic_hash` is uniquely indexed, so the second registration
    // cannot be persisted. The receptionist still needs to know who holds the
    // CNIC, so the conflict payload carries the possible duplicate rather than
    // a bare validation message.
    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('data', null)
        ->assertJsonCount(1, 'possible_duplicates')
        ->assertJsonPath('possible_duplicates.0.id', $existing->id)
        ->assertJsonPath('possible_duplicates.0.mrn', $existing->mrn)
        ->assertJsonPath('possible_duplicates.0.reasons.0', 'cnic_exact_match');

    expect(Patient::query()->where('name', 'New Person')->exists())->toBeFalse();
});

it('registers without duplicates when no candidate matches', function () {
    $response = $this->actingAs(dup_receptionist())
        ->postJson('/api/v1/patients', [
            'name' => 'Unique Person',
            'cnic' => '61101-9999999-9',
        ]);

    $response->assertCreated();
    expect($response->json('possible_duplicates'))->toBeNull();
});

it('rejects unauthenticated registration', function () {
    $this->postJson('/api/v1/patients', ['name' => 'No Auth'])
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Merge integrity
// ---------------------------------------------------------------------------

it('reassigns every child record and preserves row counts across a merge', function () {
    $survivor = dup_patient(['name' => 'Survivor Person']);
    $duplicate = dup_patient(['name' => 'Duplicate Person', 'cnic' => '42101-9999999-9']);

    $expected = seed_child_records_for($survivor);
    $dupCounts = seed_child_records_for($duplicate);

    $merged = $this->service->mergePatients($survivor, $duplicate, dup_admin(), 'duplicate registration');

    expect($merged->id)->toBe($survivor->id);

    $expectedTotal = [];
    foreach ($expected as $table => $count) {
        $expectedTotal[$table] = $count + ($dupCounts[$table] ?? 0);
    }

    assert_no_orphaned_patient_rows($survivor, $duplicate, $expectedTotal);
});

it('promotes missing identity fields from the duplicate onto the survivor', function () {
    $survivor = dup_patient(['name' => 'Keep Me', 'cnic' => null, 'phone' => null]);
    $duplicate = dup_patient(['name' => 'Also Me', 'cnic' => '42101-8888888-8', 'phone' => '+1 (555) 777-1234']);

    $merged = $this->service->mergePatients($survivor, $duplicate, dup_admin(), null);

    expect($merged->phone)->toBe('+1 (555) 777-1234');
    // CNIC remains encrypted in storage but decrypts to the promoted value.
    expect($merged->fresh()->cnic)->toBe('42101-8888888-8');
});

it('keeps the survivor MRN and frees the duplicate MRN', function () {
    $survivor = dup_patient();
    $duplicate = dup_patient();

    $survivorMrn = $survivor->mrn;
    $duplicateMrn = $duplicate->mrn;

    $this->service->mergePatients($survivor, $duplicate, dup_admin(), null);

    expect($survivor->fresh()->mrn)->toBe($survivorMrn);
    expect(Patient::where('mrn', $duplicateMrn)->exists())->toBeFalse();
});

it('refuses to merge when both patients have linked user accounts', function () {
    $survivor = dup_patient();
    $duplicate = dup_patient();

    User::factory()->create(['role' => 'Receptionist', 'patient_id' => $survivor->id]);
    User::factory()->create(['role' => 'Receptionist', 'patient_id' => $duplicate->id]);

    $this->service->mergePatients($survivor, $duplicate, dup_admin(), null);
})->throws(HttpException::class, 'Both patient records have linked user accounts');

it('moves a lone linked user account onto the survivor', function () {
    $survivor = dup_patient();
    $duplicate = dup_patient();

    $linkedUser = User::factory()->create(['role' => 'Receptionist', 'patient_id' => $duplicate->id]);

    $this->service->mergePatients($survivor, $duplicate, dup_admin(), null);

    expect($linkedUser->fresh()->patient_id)->toBe($survivor->id);
});

it('refuses self-merge', function () {
    $patient = dup_patient();

    $this->service->mergePatients($patient, $patient, dup_admin(), null);
})->throws(HttpException::class, 'Cannot merge a patient into itself.');

it('refuses cross-branch merge', function () {
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();

    $survivor = dup_patient(['branch_id' => $branchA->id]);
    $duplicate = dup_patient(['branch_id' => $branchB->id]);

    $this->service->mergePatients($survivor, $duplicate, dup_admin(), null);
})->throws(HttpException::class, 'Merging patients across branches is not allowed.');

it('rejects merge without a reason', function () {
    $survivor = dup_patient();
    $duplicate = dup_patient();

    $this->actingAs(dup_receptionist())
        ->postJson('/api/v1/patients/merge', [
            'survivor_id' => $survivor->id,
            'duplicate_id' => $duplicate->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});

it('rejects merge when duplicate_id equals survivor_id', function () {
    $survivor = dup_patient();

    $this->actingAs(dup_receptionist())
        ->postJson('/api/v1/patients/merge', [
            'survivor_id' => $survivor->id,
            'duplicate_id' => $survivor->id,
            'reason' => 'test',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('duplicate_id');
});

it('rejects reuse of the duplicate email against the survivor unique constraint', function () {
    // The patients.email unique constraint must not be violated by the merge.
    $survivor = dup_patient(['email' => 'unique@example.com']);
    $duplicate = dup_patient(['email' => null]);

    $this->service->mergePatients($survivor, $duplicate, dup_admin(), null);

    expect($survivor->fresh()->email)->toBe('unique@example.com');
});

it('records an audit event for the merge', function () {
    $survivor = dup_patient();
    $duplicate = dup_patient();

    $admin = dup_admin();

    $this->service->mergePatients($survivor, $duplicate, $admin, null);

    expect(AuditLog::query()
        ->where('action', 'patient.merged')
        ->where('auditable_id', $survivor->id)
        ->where('auditable_type', Patient::class)
        ->where('user_id', $admin->id)
        ->exists())->toBeTrue();
});
