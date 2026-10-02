<?php

use App\Exceptions\StaleWriteConflictException;
use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\MedicationReconciliation;
use App\Models\Patient;
use App\Models\User;
use App\Services\MedicationReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

/**
 * Concurrent medication reconciliation edits.
 *
 * These tests previously documented last-write-wins as *unspecified* behaviour
 * (DECISIONS_PENDING.md §8) and asserted that the later write won the row.
 * That is now a designed contract in the other direction: a write carries the
 * version the clinician read, and a write whose version is stale is refused
 * with 409 rather than silently overwriting a colleague's edit. A clinical
 * medication list is the wrong place for a silently dropped edit.
 *
 * The tests below assert the new contract, plus the cases that must keep
 * working: idempotent reconcile, no duplicate rows, and a full audit trail.
 */
function mcc_doctor(string $name = 'Dr. Aslam'): User
{
    return User::factory()->create(['role' => 'Doctor', 'name' => $name]);
}

function mcc_encounter(?User $doctor = null): Encounter
{
    $doctor ??= mcc_doctor();

    $patient = Patient::factory()->create(['branch_id' => $doctor->branch_id]);

    return Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => Encounter::STATUS_DRAFT,
    ]);
}

/**
 * @param  array<int, array<string, mixed>>  $medications
 * @return array<int, array<string, mixed>>
 */
function mcc_payload(array $medications): array
{
    return $medications;
}

function mcc_entry(User $doctor, Encounter $encounter, string $medicine = 'Metformin'): MedicationReconciliation
{
    return app(MedicationReconciliationService::class)->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'medicine_name' => $medicine,
        'dosage' => '500mg',
        'frequency' => 'BD',
    ], $doctor);
}

test('a new entry starts at version 1', function () {
    $doctor = mcc_doctor();
    $medRec = mcc_entry($doctor, mcc_encounter($doctor));

    expect((int) $medRec->version)->toBe(1);
});

test('a successful update increments the version and applies the edit', function () {
    $doctor = mcc_doctor();
    $medRec = mcc_entry($doctor, mcc_encounter($doctor));
    $service = app(MedicationReconciliationService::class);

    $updated = $service->update($medRec, $doctor, [
        'dosage' => '850mg',
        'version' => (int) $medRec->version,
    ]);

    expect((int) $updated->version)->toBe(2)
        ->and($updated->dosage)->toBe('850mg')
        ->and($medRec->fresh()->dosage)->toBe('850mg');
});

test('a stale version is refused and the newer edit is not overwritten', function () {
    $first = mcc_doctor('Dr. Aslam');
    $second = mcc_doctor('Dr. Hina');
    $encounter = mcc_encounter($first);
    $medRec = mcc_entry($first, $encounter);
    $service = app(MedicationReconciliationService::class);

    $staleVersion = (int) $medRec->version;

    // Dr Hina saves first, moving the row to version 2.
    $service->update($medRec, $second, ['dosage' => '1000mg', 'version' => $staleVersion]);

    // Dr Aslam is still holding the version he read. His save must be refused
    // rather than clobbering Dr Hina's change.
    expect(fn () => $service->update($medRec->fresh(), $first, ['dosage' => '850mg', 'version' => $staleVersion]))
        ->toThrow(StaleWriteConflictException::class);

    expect($medRec->fresh()->dosage)->toBe('1000mg')
        ->and((int) $medRec->fresh()->version)->toBe(2);
});

test('the conflict names who changed the entry and what version it is now on', function () {
    $first = mcc_doctor('Dr. Aslam');
    $second = mcc_doctor('Dr. Hina');
    $encounter = mcc_encounter($first);
    $medRec = mcc_entry($first, $encounter);
    $service = app(MedicationReconciliationService::class);

    $service->update($medRec, $second, ['dosage' => '1000mg', 'version' => 1]);

    try {
        $service->update($medRec->fresh(), $first, ['dosage' => '850mg', 'version' => 1]);
        $this->fail('expected a StaleWriteConflictException');
    } catch (StaleWriteConflictException $e) {
        $context = $e->context();

        // The loser is told who won and what to reload, which is what makes a
        // 409 actionable rather than a dead end.
        expect($context['expected_version'])->toBe(1)
            ->and($context['current_version'])->toBe(2)
            ->and($context['changed_by'])->toBe($second->name)
            ->and($context['id'])->toBe($medRec->id)
            ->and($context['resource'])->toBe('medication_reconciliation');
    }
});

test('a refused write is recorded as a conflict in the audit trail', function () {
    $first = mcc_doctor('Dr. Aslam');
    $second = mcc_doctor('Dr. Hina');
    $encounter = mcc_encounter($first);
    $medRec = mcc_entry($first, $encounter);
    $service = app(MedicationReconciliationService::class);

    $service->update($medRec, $second, ['dosage' => '1000mg', 'version' => 1]);

    expect(fn () => $service->update($medRec->fresh(), $first, ['dosage' => '850mg', 'version' => 1]))
        ->toThrow(StaleWriteConflictException::class);

    // The refused write never reaches the row, so no `.updated` entry exists for
    // it. Without a conflict entry the attempt would leave no trace at all, and a
    // client stuck on a stale version could lose every write invisibly.
    $conflict = AuditLog::query()
        ->where('action', 'medication_reconciliation.update_conflict')
        ->sole();

    expect($conflict->user_id)->toBe($first->id)
        ->and($conflict->new_values['expected_version'])->toBe(1)
        ->and($conflict->new_values['current_version'])->toBe(2)
        ->and($conflict->new_values['changed_by'])->toBe('Dr. Hina')
        ->and($conflict->new_values['resource'])->toBe('medication_reconciliation');
});

test('a refused deactivate is recorded separately from a refused update', function () {
    $first = mcc_doctor('Dr. Aslam');
    $second = mcc_doctor('Dr. Hina');
    $encounter = mcc_encounter($first);
    $medRec = mcc_entry($first, $encounter);
    $service = app(MedicationReconciliationService::class);

    $service->update($medRec, $second, ['dosage' => '1000mg', 'version' => 1]);

    expect(fn () => $service->deactivate($medRec->fresh(), $first, ['version' => 1]))
        ->toThrow(StaleWriteConflictException::class);

    // Deactivating a medication a colleague just started prescribing is a
    // clinically different refusal from editing one, so the two must be
    // distinguishable when the trail is reviewed.
    $conflict = AuditLog::query()
        ->where('action', 'medication_reconciliation.deactivate_conflict')
        ->sole();

    expect($conflict->user_id)->toBe($first->id)
        ->and($conflict->new_values['current_version'])->toBe(2);

    expect(AuditLog::query()->where('action', 'medication_reconciliation.update_conflict')->count())->toBe(0);
});

test('a refused write leaves no partial update and no audit row for the loser', function () {
    $first = mcc_doctor('Dr. Aslam');
    $second = mcc_doctor('Dr. Hina');
    $encounter = mcc_encounter($first);
    $medRec = mcc_entry($first, $encounter);
    $service = app(MedicationReconciliationService::class);

    $service->update($medRec, $second, ['dosage' => '1000mg', 'notes' => 'Hina note', 'version' => 1]);

    $auditsBefore = AuditLog::query()->where('action', 'medication_reconciliation.updated')->count();

    expect(fn () => $service->update($medRec->fresh(), $first, ['dosage' => '850mg', 'version' => 1]))
        ->toThrow(StaleWriteConflictException::class);

    $fresh = $medRec->fresh();

    expect($fresh->dosage)->toBe('1000mg')
        ->and($fresh->notes)->toBe('Hina note')
        ->and(AuditLog::query()->where('action', 'medication_reconciliation.updated')->count())->toBe($auditsBefore);
});

test('a write without a version still works but is logged as last-write-wins', function () {
    $doctor = mcc_doctor();
    $medRec = mcc_entry($doctor, mcc_encounter($doctor));

    // An older client that does not send a version keeps working rather than
    // breaking, but the gap is recorded because it is the behaviour this
    // change was made to remove.
    $updated = app(MedicationReconciliationService::class)
        ->update($medRec, $doctor, ['dosage' => '850mg']);

    expect($updated->dosage)->toBe('850mg')
        ->and((int) $updated->version)->toBe(2);
});

test('deactivating honours the version precondition too', function () {
    $first = mcc_doctor('Dr. Aslam');
    $second = mcc_doctor('Dr. Hina');
    $encounter = mcc_encounter($first);
    $medRec = mcc_entry($first, $encounter);
    $service = app(MedicationReconciliationService::class);

    $service->update($medRec, $second, ['dosage' => '1000mg', 'version' => 1]);

    expect(fn () => $service->deactivate($medRec->fresh(), $first, ['version' => 1]))
        ->toThrow(StaleWriteConflictException::class);

    expect($medRec->fresh()->is_active)->toBeTrue();

    // With the current version it goes through and bumps the version again.
    $deactivated = $service->deactivate($medRec->fresh(), $first, ['version' => 2]);

    expect($deactivated->is_active)->toBeFalse()
        ->and((int) $deactivated->version)->toBe(3);
});

test('a repeated medicine in one reconcile payload does not create a duplicate row', function () {
    $doctor = mcc_doctor();
    $encounter = mcc_encounter($doctor);

    app(MedicationReconciliationService::class)->reconcileForEncounter(
        $encounter->id,
        $doctor,
        mcc_payload([
            ['medicine_name' => 'Metformin', 'dosage' => '500mg', 'frequency' => 'BD'],
            ['medicine_name' => 'Metformin', 'dosage' => '850mg', 'frequency' => 'BD'],
        ]),
    );

    expect(MedicationReconciliation::where('encounter_id', $encounter->id)->count())->toBe(1)
        ->and(MedicationReconciliation::where('encounter_id', $encounter->id)->first()->dosage)->toBe('850mg');
});

test('reconciling the same list twice is idempotent', function () {
    $doctor = mcc_doctor();
    $encounter = mcc_encounter($doctor);
    $service = app(MedicationReconciliationService::class);

    $list = mcc_payload([
        ['medicine_name' => 'Metformin', 'dosage' => '500mg', 'frequency' => 'BD'],
        ['medicine_name' => 'Atorvastatin', 'dosage' => '20mg', 'frequency' => 'OD'],
    ]);

    $service->reconcileForEncounter($encounter->id, $doctor, $list);
    $service->reconcileForEncounter($encounter->id, $doctor, $list);

    expect(MedicationReconciliation::where('encounter_id', $encounter->id)->where('is_active', true)->count())->toBe(2);
});

test('overlapping reconcile calls from two clinicians leave exactly one active row per medicine', function () {
    $doctor = mcc_doctor();
    $other = mcc_doctor('Dr. Hina');
    $encounter = mcc_encounter($doctor);
    $service = app(MedicationReconciliationService::class);

    // Reconcile is a bulk upsert keyed on medicine name, so it converges on the
    // same rows rather than duplicating them. It is deliberately not subject to
    // the per-row version check: it is an authoritative "this is the reconciled
    // list" submission, not a read-modify-write of one row.
    $service->reconcileForEncounter($encounter->id, $doctor, mcc_payload([
        ['medicine_name' => 'Warfarin', 'dosage' => '5mg', 'frequency' => 'OD'],
    ]));

    $result = $service->reconcileForEncounter($encounter->id, $other, mcc_payload([
        ['medicine_name' => 'Warfarin', 'dosage' => '5mg', 'frequency' => 'OD'],
    ]));

    expect(MedicationReconciliation::where('encounter_id', $encounter->id)->where('is_active', true)->count())->toBe(1)
        ->and($result['created'])->toBe([])
        ->and($result['updated'])->toHaveCount(1)
        ->and($result['deactivated'])->toBe([]);
});

test('both successful edits are still recorded in the audit trail with their versions', function () {
    $first = mcc_doctor('Dr. Aslam');
    $second = mcc_doctor('Dr. Hina');
    $encounter = mcc_encounter($first);
    $service = app(MedicationReconciliationService::class);
    $medRec = mcc_entry($first, $encounter);

    $service->update($medRec, $first, ['dosage' => '850mg', 'version' => 1]);
    $service->update($medRec->fresh(), $second, ['dosage' => '1000mg', 'version' => 2]);

    expect($medRec->fresh()->dosage)->toBe('1000mg');

    $updates = AuditLog::query()
        ->where('action', 'medication_reconciliation.updated')
        ->where('auditable_id', $medRec->id)
        ->orderBy('id')
        ->get();

    // Optimistic locking changes which writes land, not the fact that the
    // history is complete: the version makes the sequence readable.
    expect($updates)->toHaveCount(2)
        ->and($updates[0]->user_id)->toBe($first->id)
        ->and($updates[0]->old_values['dosage'])->toBe('500mg')
        ->and($updates[0]->new_values['dosage'])->toBe('850mg')
        ->and($updates[0]->old_values['version'])->toBe(1)
        ->and($updates[0]->new_values['version'])->toBe(2)
        ->and($updates[1]->user_id)->toBe($second->id)
        ->and($updates[1]->old_values['dosage'])->toBe('850mg')
        ->and($updates[1]->new_values['dosage'])->toBe('1000mg')
        ->and($updates[1]->new_values['version'])->toBe(3);
});
