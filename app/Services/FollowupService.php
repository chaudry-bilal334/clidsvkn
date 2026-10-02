<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Followup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FollowupService
{
    /**
     * Complete a follow-up, guarded against racing mutations by a row lock.
     *
     * Only pending follow-ups can be completed. Completion records the acting
     * user and timestamp so the task is traceable.
     */
    public function complete(Followup $followup, User $actor): Followup
    {
        return DB::transaction(function () use ($followup, $actor): Followup {
            /** @var Followup $locked */
            $locked = Followup::query()->lockForUpdate()->findOrFail($followup->id);

            $this->assertPending($locked);

            $previous = $locked->status;

            $locked->status = Followup::STATUS_COMPLETED;
            $locked->completed_at = now();
            $locked->completed_by = $actor->id;
            $locked->save();

            AuditLog::record(
                $actor,
                'followup.completed',
                $locked,
                oldValues: ['status' => $previous],
                newValues: ['status' => Followup::STATUS_COMPLETED],
            );

            return $locked;
        });
    }

    /**
     * Cancel a scheduled follow-up.
     */
    public function cancel(Followup $followup, User $actor): Followup
    {
        return DB::transaction(function () use ($followup, $actor): Followup {
            /** @var Followup $locked */
            $locked = Followup::query()->lockForUpdate()->findOrFail($followup->id);

            $this->assertPending($locked);

            $previous = $locked->status;

            $locked->status = Followup::STATUS_CANCELLED;
            $locked->save();

            AuditLog::record(
                $actor,
                'followup.cancelled',
                $locked,
                oldValues: ['status' => $previous],
                newValues: ['status' => Followup::STATUS_CANCELLED],
            );

            return $locked;
        });
    }

    private function assertPending(Followup $followup): void
    {
        if ($followup->status !== Followup::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ["Only pending follow-ups can be actioned (current status: {$followup->status})."],
            ]);
        }
    }
}
