<?php

namespace App\Services;

use App\Exceptions\StaleWriteConflictException;
use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\MedicationReconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MedicationReconciliationService
{
    /**
     * Create a new medication reconciliation entry.
     *
     * @param  array{
     *     patient_id: int,
     *     encounter_id: int|null,
     *     medicine_name: string,
     *     dosage: string|null,
     *     frequency: string|null,
     *     source: string,
     *     is_active: bool,
     *     reconciled_at: string|null,
     *     reconciled_by: int|null,
     *     notes: string|null
     * }  $fields
     */
    public function create(array $fields, User $actor): MedicationReconciliation
    {
        return DB::transaction(function () use ($fields, $actor): MedicationReconciliation {
            $medRec = MedicationReconciliation::create([
                'patient_id' => $fields['patient_id'],
                'encounter_id' => $fields['encounter_id'] ?? null,
                'medicine_name' => $fields['medicine_name'],
                'dosage' => $fields['dosage'],
                'frequency' => $fields['frequency'],
                'source' => $fields['source'] ?? MedicationReconciliation::SOURCE_CLINICIAN,
                'is_active' => $fields['is_active'] ?? true,
                'reconciled_at' => $fields['reconciled_at'] ?? now(),
                'reconciled_by' => $fields['reconciled_by'] ?? $actor->id,
                'notes' => $fields['notes'] ?? null,
            ]);

            AuditLog::record(
                $actor,
                'medication_reconciliation.created',
                $medRec,
                newValues: [
                    'patient_id' => $fields['patient_id'],
                    'encounter_id' => $fields['encounter_id'],
                    'medicine_name' => $fields['medicine_name'],
                    'dosage' => $fields['dosage'],
                    'frequency' => $fields['frequency'],
                    'source' => $fields['source'] ?? MedicationReconciliation::SOURCE_CLINICIAN,
                    'is_active' => $fields['is_active'] ?? true,
                ],
            );

            return $medRec;
        });
    }

    /**
     * Update a medication reconciliation entry.
     *
     * `$fields['version']` carries the version the caller read. When it does not
     * match the stored version the write is refused with a 409 rather than
     * overwriting a colleague's later edit (see
     * `StaleWriteConflictException`). Omitting `version` is still allowed so an
     * older client keeps working, but it opts out of the check — which is
     * logged, because that is exactly the last-write-wins path this replaced.
     *
     * @param  array{
     *     medicine_name: string|null,
     *     dosage: string|null,
     *     frequency: string|null,
     *     is_active: bool|null,
     *     notes: string|null,
     *     version: int|null
     * }  $fields
     */
    public function update(MedicationReconciliation $medRec, User $actor, array $fields): MedicationReconciliation
    {
        $expectedVersion = $fields['version'] ?? null;
        unset($fields['version']);

        /**
         * The transaction returns either the updated row or a conflict marker rather
         * than throwing, so the refusal and its audit entry are handled *outside* the
         * transaction. Throwing inside would roll the transaction back and take any
         * audit row written there with it: a refused write has to leave a trace, and
         * that trace must survive the rollback that caused it.
         *
         * @return MedicationReconciliation|array{conflict: MedicationReconciliation, expected: int}
         */
        $result = DB::transaction(function () use ($medRec, $actor, $fields, $expectedVersion): MedicationReconciliation|array {
            if ($expectedVersion === null) {
                Log::warning('[medrec] update without a version precondition; last-write-wins applies', [
                    'medication_reconciliation_id' => $medRec->id,
                    'actor_id' => $actor->id,
                ]);
            }

            return $this->applyWrite($medRec, $actor, $fields, 'update', $expectedVersion);
        });

        if (is_array($result)) {
            throw $this->recordConflictAndThrow($result['conflict'], $actor, 'update', $result['expected']);
        }

        return $result;
    }

    /**
     * Deactivate a medication reconciliation entry (soft delete equivalent).
     *
     * Honours the same `version` precondition as `update()`, because
     * deactivating a medication a colleague has just started prescribing is
     * exactly the kind of edit that must not be silently lost.
     *
     * @param  array{version: int|null}  $fields
     */
    public function deactivate(MedicationReconciliation $medRec, User $actor, array $fields = []): MedicationReconciliation
    {
        $expectedVersion = $fields['version'] ?? null;

        /** @var MedicationReconciliation|array{conflict: MedicationReconciliation, expected: int} $result */
        $result = DB::transaction(fn (): MedicationReconciliation|array => $this->applyWrite($medRec, $actor, [], 'deactivate', $expectedVersion));

        if (is_array($result)) {
            throw $this->recordConflictAndThrow($result['conflict'], $actor, 'deactivate', $result['expected']);
        }

        return $result;
    }

    /**
     * Run a version-checked write inside the transaction.
     *
     * The row is re-read under a `lockForUpdate` and compared there rather than
     * trusting the instance the caller passed in, so two requests that both read
     * version 3 cannot both succeed: the second waits on the row lock, re-reads
     * version 4, and is refused. Comparing the caller's stale in-memory model
     * instead would let both through.
     *
     * Returns a conflict marker instead of throwing; see `update()`.
     *
     * @param  array<string, mixed>  $fields
     * @param  'update'|'deactivate'  $operation
     * @return MedicationReconciliation|array{conflict: MedicationReconciliation, expected: int}
     */
    private function applyWrite(
        MedicationReconciliation $medRec,
        User $actor,
        array $fields,
        string $operation,
        ?int $expectedVersion,
    ): MedicationReconciliation|array {
        $current = MedicationReconciliation::query()
            ->lockForUpdate()
            ->findOrFail($medRec->id);

        if ($expectedVersion !== null && (int) $expectedVersion !== (int) $current->version) {
            return ['conflict' => $current, 'expected' => (int) $expectedVersion];
        }

        $editable = ['medicine_name', 'dosage', 'frequency', 'is_active', 'notes'];

        if ($operation === 'deactivate') {
            if (! $current->is_active) {
                return $current;
            }

            $old = ['is_active' => true, 'version' => (int) $current->version];

            $current->is_active = false;
            $current->version = (int) $current->version + 1;
            $current->save();

            AuditLog::record(
                $actor,
                'medication_reconciliation.deactivated',
                $current,
                oldValues: $old,
                newValues: ['is_active' => false, 'version' => (int) $current->version],
            );

            return $current;
        }

        $old = $current->only($editable);

        $current->fill($fields);
        $current->version = (int) $current->version + 1;
        // Keep the row's author and time in step with the row, so a later
        // conflict can name the clinician who actually changed it rather
        // than whoever first created the entry.
        $current->reconciled_by = $actor->id;
        $current->reconciled_at = now();
        $current->save();

        AuditLog::record(
            $actor,
            'medication_reconciliation.updated',
            $current,
            oldValues: $old + ['version' => (int) $current->version - 1],
            newValues: $current->only($editable) + ['version' => (int) $current->version],
        );

        return $current;
    }

    /**
     * Build (and audit) the refusal for a write whose version precondition is stale.
     *
     * A refused write must leave a trace. The losing edit never reaches the row, so
     * `medication_reconciliation.updated` is not written and the only record that
     * this clinician tried to change something is this entry. Without it, a
     * systematically ignored conflict -- a client stuck on an old version, or a
     * clinician repeatedly losing the same race -- would leave no evidence at all,
     * which is the situation audit logging exists to catch.
     *
     * @param  'update'|'deactivate'  $operation
     */
    private function recordConflictAndThrow(
        MedicationReconciliation $current,
        User $actor,
        string $operation,
        int $expectedVersion,
    ): StaleWriteConflictException {
        $context = [
            'resource' => 'medication_reconciliation',
            'id' => $current->id,
            'expected_version' => $expectedVersion,
            'current_version' => (int) $current->version,
            'changed_by' => $current->reconciler?->name,
            'changed_at' => $current->updated_at?->toIso8601String(),
        ];

        // Written here, deliberately outside the transaction that has already rolled
        // back. `DB::afterCommit` would be the wrong tool: a conflict always throws,
        // the transaction therefore always rolls back, and Laravel discards
        // after-commit callbacks on rollback -- so the audit row would never be
        // written at all. Recording it directly is what makes the refusal auditable.
        //
        // The write is a plain insert on its own, so a failure to record the conflict
        // cannot take down the refusal itself. If this insert throws, the audit write
        // is the bug to investigate, not a reason to let the write through.
        try {
            AuditLog::record(
                $actor,
                "medication_reconciliation.{$operation}_conflict",
                $current,
                newValues: $context,
            );
        } catch (\Throwable $auditFailure) {
            Log::error('[medrec] failed to audit a refused write', [
                'medication_reconciliation_id' => $current->id,
                'actor_id' => $actor->id,
                'operation' => $operation,
                'exception' => $auditFailure->getMessage(),
            ]);
        }

        return new StaleWriteConflictException(
            'This medication entry was changed by someone else while you were editing it. Reload and try again.',
            $context,
        );
    }

    /**
     * Reconcile a list of medications for an encounter (bulk upsert).
     *
     * @param  array<int, array{
     *     medicine_name: string,
     *     dosage: string|null,
     *     frequency: string|null,
     *     source: string,
     *     is_active: bool,
     *     notes: string|null
     * }>  $medications
     */
    public function reconcileForEncounter(int $encounterId, User $actor, array $medications): array
    {
        return DB::transaction(function () use ($encounterId, $actor, $medications): array {
            $encounter = Encounter::findOrFail($encounterId);

            // Key both sides by medicine name so a payload that repeats a
            // medicine, or a second reconcile call carrying the same list,
            // updates the single active row instead of inserting a duplicate.
            $incoming = collect($medications)->keyBy('medicine_name');

            $existing = MedicationReconciliation::where('encounter_id', $encounterId)
                ->where('is_active', true)
                ->get()
                ->keyBy('medicine_name');

            $created = [];
            $updated = [];
            $deactivated = [];

            foreach ($incoming as $medName => $med) {
                $attributes = [
                    'dosage' => $med['dosage'],
                    'frequency' => $med['frequency'],
                    'source' => $med['source'] ?? MedicationReconciliation::SOURCE_CLINICIAN,
                    'is_active' => $med['is_active'] ?? true,
                    'notes' => $med['notes'] ?? null,
                ];

                if (isset($existing[$medName])) {
                    $existingMed = $existing[$medName];
                    $existingMed->fill($attributes);
                    $existingMed->save();

                    $updated[] = $existingMed->fresh();

                    continue;
                }

                $created[] = $this->create([
                    'patient_id' => $encounter->patient_id,
                    'encounter_id' => $encounterId,
                    'medicine_name' => $medName,
                ] + $attributes, $actor);
            }

            // Deactivate medications the new list no longer carries.
            foreach ($existing as $name => $medRec) {
                if (! $incoming->has($name)) {
                    $medRec->is_active = false;
                    $medRec->save();
                    $deactivated[] = $medRec->fresh();
                }
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'deactivated' => $deactivated,
            ];
        });
    }
}
