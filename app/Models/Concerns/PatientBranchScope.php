<?php

namespace App\Models\Concerns;

use App\Services\BranchContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Partitions tenant data through the owning patient's branch.
 *
 * Encounter and Prescription do not carry their own branch_id column; their
 * tenant is the branch of the patient they belong to. This global scope keeps
 * cross-branch staff from reading or mutating another branch's record by
 * guessing an ID. It mirrors BranchScope's bypass semantics: admins and
 * authorized cross-branch users are not filtered unless they opt into a branch.
 */
trait PatientBranchScope
{
    /**
     * Boot the patient-branch scoping for the model.
     */
    protected static function bootPatientBranchScope(): void
    {
        static::addGlobalScope('branch', function (Builder $builder): void {
            if (BranchContext::bypassed() || BranchContext::id() === null) {
                return;
            }

            $builder->whereHas('patient', function (Builder $patientQuery): void {
                $patientQuery->where('branch_id', BranchContext::id());
            });
        });
    }
}
