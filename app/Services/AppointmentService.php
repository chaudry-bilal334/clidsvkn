<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Appointment lifecycle (PRD §2, §6).
 *
 * Owns every write to `appointments` so the controller stays thin:
 * Authorization -> Validation -> Service -> Transaction -> Audit -> Response.
 *
 * Two guarantees live here and nowhere else:
 *
 * 1. Only transitions declared in {@see Appointment::TRANSITIONS} are accepted.
 *    Rejected attempts are audited too, so a client hammering illegal
 *    transitions leaves a trace (PRD §6).
 * 2. Slot uniqueness is enforced twice — the availability check plus the
 *    database-level active-slot backstop. Losing that race is a clean 422,
 *    never a 500.
 */
class AppointmentService
{
    public function __construct(private readonly DoctorAvailabilityService $availability) {}

    /**
     * Book a new appointment in the initial `booked` state.
     *
     * @param  array{patient_id: int, doctor_id: int, scheduled_at: string, reason?: string|null, notes?: string|null}  $fields
     *
     * @throws ValidationException when the slot is unavailable or was just taken
     */
    public function book(array $fields, User $actor): Appointment
    {
        $this->availability->assertSlotAvailable($fields['doctor_id'], $fields['scheduled_at']);

        try {
            return DB::transaction(function () use ($fields, $actor): Appointment {
                $appointment = Appointment::create([
                    'patient_id' => $fields['patient_id'],
                    'doctor_id' => $fields['doctor_id'],
                    'scheduled_at' => $fields['scheduled_at'],
                    'status' => Appointment::STATUS_BOOKED,
                    'status_changed_at' => now(),
                    'reason' => $fields['reason'] ?? null,
                    'notes' => $fields['notes'] ?? null,
                ]);

                AuditLog::record(
                    $actor,
                    'appointment.booked',
                    $appointment,
                    newValues: [
                        'status' => Appointment::STATUS_BOOKED,
                        'scheduled_at' => $appointment->scheduled_at?->toDateTimeString(),
                        'doctor_id' => $appointment->doctor_id,
                    ],
                );

                return $appointment;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the booking race to a concurrent request that committed the
            // same (doctor, slot) between the availability check and the insert.
            // The database backstop is authoritative; surface a clean 422.
            throw ValidationException::withMessages([
                'scheduled_at' => ['This slot was just booked by another request.'],
            ]);
        }
    }

    /**
     * Move an appointment to a new status, rejecting transitions the PRD §6
     * state machine does not permit.
     *
     * @throws ValidationException on an illegal transition
     */
    public function transition(Appointment $appointment, string $target, User $actor, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $target, $actor, $reason): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);

            $from = (string) $locked->status;

            if ($from === $target) {
                throw ValidationException::withMessages([
                    'status' => ["This appointment is already {$target}."],
                ]);
            }

            if (! $locked->canTransitionTo($target)) {
                $allowed = $locked->allowedTransitions();

                // Rejected transitions are audited: a client repeatedly trying
                // to rewind a completed appointment is a signal worth keeping.
                AuditLog::record($actor, 'appointment.transition_rejected', $locked, oldValues: [
                    'status' => $from,
                ], newValues: [
                    'requested_status' => $target,
                    'allowed' => $allowed,
                ]);

                throw ValidationException::withMessages([
                    'status' => [
                        $allowed === []
                            ? "{$from} is a final state; {$target} is not reachable."
                            : "Cannot move an appointment from {$from} to {$target}.",
                    ],
                ]);
            }

            // Re-entering the active set must still respect the slot backstop.
            if (in_array($target, Appointment::ACTIVE_STATUSES, true)
                && ! in_array($from, Appointment::ACTIVE_STATUSES, true)) {
                $this->availability->assertSlotAvailable(
                    (int) $locked->doctor_id,
                    (string) $locked->scheduled_at?->format('Y-m-d H:i:s'),
                    ignoreAppointmentId: $locked->id,
                );
            }

            $locked->status = $target;
            $locked->status_changed_at = now();

            if ($target === Appointment::STATUS_CANCELLED) {
                $locked->cancelled_at = now();
                $locked->cancellation_reason = $reason;
            }

            $locked->save();

            AuditLog::record($actor, 'appointment.status_changed', $locked, oldValues: [
                'status' => $from,
            ], newValues: array_filter([
                'status' => $target,
                'cancellation_reason' => $locked->cancellation_reason,
            ], fn (mixed $value): bool => $value !== null));

            return $locked;
        });
    }

    /**
     * Reschedule an appointment, re-checking the slot backstop.
     *
     * @throws ValidationException when the new slot is unavailable or was just taken
     */
    public function reschedule(Appointment $appointment, string $scheduledAt, User $actor, ?int $doctorId = null): Appointment
    {
        $targetDoctorId = $doctorId ?? (int) $appointment->doctor_id;

        $this->availability->assertSlotAvailable($targetDoctorId, $scheduledAt, ignoreAppointmentId: $appointment->id);

        try {
            return DB::transaction(function () use ($appointment, $scheduledAt, $actor, $targetDoctorId): Appointment {
                /** @var Appointment $locked */
                $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);

                if (in_array((string) $locked->status, Appointment::TERMINAL_STATUSES, true)) {
                    throw ValidationException::withMessages([
                        'scheduled_at' => ["A {$locked->status} appointment cannot be rescheduled."],
                    ]);
                }

                $previous = [
                    'scheduled_at' => $locked->scheduled_at?->toDateTimeString(),
                    'doctor_id' => $locked->doctor_id,
                ];

                $locked->scheduled_at = $scheduledAt;
                $locked->doctor_id = $targetDoctorId;
                $locked->status_changed_at = now();
                $locked->save();

                AuditLog::record($actor, 'appointment.rescheduled', $locked, oldValues: $previous, newValues: [
                    'scheduled_at' => $locked->scheduled_at?->toDateTimeString(),
                    'doctor_id' => $targetDoctorId,
                ]);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['This slot was just booked by another request.'],
            ]);
        }
    }

    /**
     * Update the non-clinical descriptive fields of an appointment.
     *
     * @param  array<string, mixed>  $fields
     */
    public function updateDetails(Appointment $appointment, array $fields, User $actor): Appointment
    {
        $attributes = array_intersect_key($fields, array_flip(['reason', 'notes']));

        if ($attributes === []) {
            throw ValidationException::withMessages([
                'appointment' => ['Nothing to update.'],
            ]);
        }

        return DB::transaction(function () use ($appointment, $attributes, $actor): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);

            $previous = array_intersect_key($locked->only(array_keys($attributes)), $attributes);

            $locked->fill($attributes)->save();

            AuditLog::record($actor, 'appointment.updated', $locked, oldValues: $previous, newValues: $attributes);

            return $locked;
        });
    }
}
