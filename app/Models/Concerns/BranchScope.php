<?php

namespace App\Models\Concerns;

use App\Services\BranchContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies tenant-branch partitioning to a model.
 *
 * Adding this trait to a model restricts all queries to the currently active
 * branch (from the X-Branch-ID header or the authenticated user's assigned
 * branch) while the request has an active branch. Super admins and authorized
 * cross-branch users bypass the scope unless they explicitly select a branch.
 *
 * New records automatically receive the active branch on create.
 */
trait BranchScope
{
    /**
     * Boot the branch scoping for the model.
     */
    protected static function bootBranchScope(): void
    {
        static::addGlobalScope('branch', function (Builder $builder): void {
            if (BranchContext::bypassed() || BranchContext::id() === null) {
                return;
            }

            $builder->where($builder->qualifyColumn('branch_id'), BranchContext::id());
        });

        static::creating(function ($model): void {
            if (BranchContext::id() === null || isset($model->branch_id)) {
                return;
            }

            $model->branch_id = BranchContext::id();
        });
    }

    /**
     * Column that separates rows by branch on this model.
     */
    public function getBranchColumn(): string
    {
        return 'branch_id';
    }
}
