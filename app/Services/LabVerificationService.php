<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LabOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LabVerificationService
{
    /**
     * Approve a completed lab result. The verifier must be a different user
     * from the person who recorded the result, satisfying the second-person
     * verification workflow.
     */
    public function verify(LabOrder $labOrder, User $verifier): LabOrder
    {
        return DB::transaction(function () use ($labOrder, $verifier): LabOrder {
            /** @var LabOrder $locked */
            $locked = LabOrder::query()->lockForUpdate()->findOrFail($labOrder->id);

            if ($locked->status !== LabOrder::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'lab_order' => ['Only completed results can be verified.'],
                ]);
            }

            if (! in_array($locked->verification_state, LabOrder::REVERIFIABLE_STATES, true)) {
                throw ValidationException::withMessages([
                    'lab_order' => ['This result has already been verified.'],
                ]);
            }

            if (blank($locked->result)) {
                throw ValidationException::withMessages([
                    'lab_order' => ['A result must be present before verification.'],
                ]);
            }

            if ($locked->recorded_by === null) {
                throw ValidationException::withMessages([
                    'lab_order' => ['The result has not been recorded for verification.'],
                ]);
            }

            if ((int) $locked->recorded_by === (int) $verifier->id) {
                throw ValidationException::withMessages([
                    'verifier_id' => ['The result must be verified by a different user than the one who recorded it.'],
                ]);
            }

            $locked->update([
                'verifier_id' => $verifier->id,
                'verified_at' => now(),
                'verification_state' => LabOrder::VERIFICATION_VERIFIED,
            ]);

            AuditLog::record(
                $verifier,
                'lab.verified',
                $locked,
                newValues: ['verifier_id' => $verifier->id],
            );

            return $locked;
        });
    }

    /**
     * Record the sample for an order, transitioning it from `pending` (ordered)
     * to `sample_collected`. This is the first step of the lab workflow
     * (PRD §9 / DESIGN §11: `Ordered → Sample Collected → Processing → Result
     * Entered`) and is a server-derived transition: the client never supplies a
     * status, only an optional sample identifier.
     *
     * The identifier is unique per branch *when set* — a duplicate non-NULL
     * value in the same branch is rejected with a readable 422. The composite
     * unique index on (branch_id, sample_identifier) is the concurrency
     * backstop; this pre-check just produces a friendly message.
     */
    public function collectSample(LabOrder $labOrder, User $actor, ?string $sampleIdentifier): LabOrder
    {
        return DB::transaction(function () use ($labOrder, $actor, $sampleIdentifier): LabOrder {
            /** @var LabOrder $locked */
            $locked = LabOrder::query()->lockForUpdate()->findOrFail($labOrder->id);

            if ($locked->sample_collected_at !== null) {
                throw ValidationException::withMessages([
                    'lab_order' => ['This sample has already been collected.'],
                ]);
            }

            if ($locked->status !== LabOrder::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'lab_order' => ['Only an ordered lab order can have its sample collected.'],
                ]);
            }

            $identifier = $sampleIdentifier !== null && trim($sampleIdentifier) !== ''
                ? trim($sampleIdentifier)
                : null;

            if ($identifier !== null) {
                $duplicate = LabOrder::query()
                    ->where('branch_id', $locked->branch_id)
                    ->where('sample_identifier', $identifier)
                    ->where('id', '!=', $locked->id)
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'sample_identifier' => ['This sample identifier is already in use in this branch.'],
                    ]);
                }
            }

            $previous = [
                'status' => $locked->status,
                'sample_collected_at' => $locked->sample_collected_at?->toDateTimeString(),
            ];

            $locked->update([
                'status' => LabOrder::STATUS_SAMPLE_COLLECTED,
                'sample_collected_at' => now(),
                'sample_collected_by' => $actor->id,
                'sample_identifier' => $identifier,
            ]);

            AuditLog::record(
                $actor,
                'lab.sample_collected',
                $locked,
                oldValues: $previous,
                newValues: [
                    'status' => LabOrder::STATUS_SAMPLE_COLLECTED,
                    'sample_collected_at' => $locked->sample_collected_at?->toDateTimeString(),
                    'sample_identifier' => $identifier,
                ],
            );

            return $locked;
        });
    }

    /**
     * Record or re-record the result on an order that has NOT yet been
     * verified. Once an order carries a second-person verification the only
     * legal path to change the result is {@see self::amend()}, so this method
     * refuses to touch a verified or amended order (PRD §7, §9).
     *
     * Fail-closed: a result cannot be recorded until the sample has been
     * collected, so an order that skipped the collection step can never carry
     * a result (PRD §9).
     */
    public function recordResult(LabOrder $labOrder, User $actor, string $result, string $status): LabOrder
    {
        return DB::transaction(function () use ($labOrder, $actor, $result, $status): LabOrder {
            /** @var LabOrder $locked */
            $locked = LabOrder::query()->lockForUpdate()->findOrFail($labOrder->id);

            if (in_array($locked->verification_state, [LabOrder::VERIFICATION_VERIFIED, LabOrder::VERIFICATION_AMENDED], true)) {
                throw ValidationException::withMessages([
                    'result' => ['This result has already been verified. Use the amendment endpoint with a reason to change it.'],
                ]);
            }

            if ($locked->sample_collected_at === null) {
                throw ValidationException::withMessages([
                    'result' => ['A sample must be collected before a result can be recorded.'],
                ]);
            }

            $previous = [
                'result' => $locked->result,
                'status' => $locked->status,
            ];

            $locked->update([
                'result' => $result,
                'status' => $status,
                'recorded_at' => now(),
                'recorded_by' => $locked->recorded_by ?? $actor->id,
                'verification_state' => LabOrder::VERIFICATION_PENDING,
            ]);

            AuditLog::record(
                $actor,
                'lab.result_recorded',
                $locked,
                oldValues: $previous,
                newValues: ['result' => $result, 'status' => $status],
            );

            return $locked;
        });
    }

    /**
     * Amend a verified result. Preserves the original record and requires a
     * fresh second-person verification before the amended result is trusted.
     */
    public function amend(LabOrder $labOrder, User $actor, string $reason, ?string $result): LabOrder
    {
        return DB::transaction(function () use ($labOrder, $actor, $reason, $result): LabOrder {
            /** @var LabOrder $locked */
            $locked = LabOrder::query()->lockForUpdate()->findOrFail($labOrder->id);

            if ($locked->verification_state !== LabOrder::VERIFICATION_VERIFIED) {
                throw ValidationException::withMessages([
                    'lab_order' => ['Only a verified result can be amended.'],
                ]);
            }

            $previous = [
                'result' => $locked->result,
                'verified_at' => $locked->verified_at?->toDateTimeString(),
            ];

            $locked->update([
                'result' => $result ?? $locked->result,
                'verification_state' => LabOrder::VERIFICATION_AMENDED,
                'verifier_id' => null,
                'verified_at' => null,
                // The amender becomes the recorder of the current value, so the
                // existing recorder-is-not-the-verifier guard also stops them
                // from signing off their own change.
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            AuditLog::record(
                $actor,
                'lab.amended',
                $locked,
                oldValues: $previous,
                newValues: [
                    'amendment_reason' => $reason,
                    'result' => $locked->result,
                ],
            );

            return $locked;
        });
    }

    /**
     * Change only the workflow status of an order, leaving the result alone.
     *
     * Split out from recordResult so a status-only edit on a verified order is
     * still transactional and audited rather than a bare `fill()->save()`.
     */
    public function changeStatus(LabOrder $labOrder, User $actor, string $status): LabOrder
    {
        return DB::transaction(function () use ($labOrder, $actor, $status): LabOrder {
            /** @var LabOrder $locked */
            $locked = LabOrder::query()->lockForUpdate()->findOrFail($labOrder->id);

            if ($locked->status === $status) {
                return $locked;
            }

            // Moving to completed without a result would produce a completed
            // order the verifier cannot approve.
            if ($status === LabOrder::STATUS_COMPLETED && blank($locked->result)) {
                throw ValidationException::withMessages([
                    'result' => ['A result must be present before the order can be completed.'],
                ]);
            }

            $previous = $locked->status;

            $locked->update(['status' => $status]);

            AuditLog::record(
                $actor,
                'lab.status_changed',
                $locked,
                oldValues: ['status' => $previous],
                newValues: ['status' => $status],
            );

            return $locked;
        });
    }

    /**
     * Finalize a verified result.
     *
     * Finalization is the point after which the result stops being amendable
     * in the normal flow. It only accepts a verified order, so the finalized
     * state cannot be reached by amending an unverified one.
     */
    public function finalize(LabOrder $labOrder, User $actor): LabOrder
    {
        return DB::transaction(function () use ($labOrder, $actor): LabOrder {
            /** @var LabOrder $locked */
            $locked = LabOrder::query()->lockForUpdate()->findOrFail($labOrder->id);

            if ($locked->verification_state !== LabOrder::VERIFICATION_VERIFIED) {
                throw ValidationException::withMessages([
                    'lab_order' => ['Only a verified result can be finalized.'],
                ]);
            }

            if ($locked->finalized_at !== null) {
                throw ValidationException::withMessages([
                    'lab_order' => ['This result is already finalized.'],
                ]);
            }

            $locked->update([
                'finalized_at' => now(),
                'finalized_by' => $actor->id,
            ]);

            AuditLog::record(
                $actor,
                'lab.finalized',
                $locked,
                newValues: [
                    'finalized_at' => $locked->finalized_at?->toDateTimeString(),
                    'result' => $locked->result,
                ],
            );

            return $locked;
        });
    }
}
