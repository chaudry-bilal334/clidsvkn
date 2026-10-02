<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QueueService
{
    /**
     * Check a booked appointment into the queue.
     */
    public function checkIn(Appointment $appointment, User $actor, ?int $branchId = null): QueueEntry
    {
        return DB::transaction(function () use ($appointment, $actor, $branchId): QueueEntry {
            /** @var Appointment $locked */
            $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);

            // Checked before the status guard so a repeat check-in reports the
            // specific cause rather than a generic lifecycle rejection.
            if ($locked->queueEntry()->exists()) {
                throw ValidationException::withMessages([
                    'appointment_id' => ['This appointment has already been checked in.'],
                ]);
            }

            // Check-in is the `arrived` edge of the PRD §6 lifecycle.
            if (! in_array($locked->status, [Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED], true)) {
                throw ValidationException::withMessages([
                    'appointment_id' => ['Only booked or confirmed appointments can be checked in.'],
                ]);
            }

            $locked->update([
                'status' => Appointment::STATUS_ARRIVED,
                'status_changed_at' => now(),
            ]);

            $entry = QueueEntry::create([
                'appointment_id' => $locked->id,
                'patient_id' => $locked->patient_id,
                'branch_id' => $branchId,
                'status' => QueueEntry::STATUS_ARRIVED,
                'checked_in_at' => now(),
            ]);

            AuditLog::record(
                $actor,
                'queue.checked_in',
                $entry,
                newValues: [
                    'appointment_id' => $locked->id,
                    'patient_id' => $locked->patient_id,
                ],
            );

            return $entry;
        });
    }

    /**
     * Transition a queue entry along the approved state machine.
     */
    public function transition(QueueEntry $queueEntry, string $status, User $actor): QueueEntry
    {
        return DB::transaction(function () use ($queueEntry, $status, $actor): QueueEntry {
            /** @var QueueEntry $locked */
            $locked = QueueEntry::query()->lockForUpdate()->findOrFail($queueEntry->id);

            $allowed = QueueEntry::TRANSITIONS[$locked->status] ?? [];

            if (! in_array($status, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot transition the queue entry from '{$locked->status}' to '{$status}'."],
                ]);
            }

            $previous = $locked->status;
            $locked->update(['status' => $status]);

            match ($status) {
                QueueEntry::STATUS_COMPLETED => $locked->appointment()->update(['status' => 'completed']),
                QueueEntry::STATUS_CANCELLED => $locked->appointment()->update(['status' => 'cancelled']),
                default => null,
            };

            AuditLog::record(
                $actor,
                'queue.transitioned',
                $locked,
                oldValues: ['status' => $previous],
                newValues: ['status' => $status],
            );

            return $locked;
        });
    }
}
