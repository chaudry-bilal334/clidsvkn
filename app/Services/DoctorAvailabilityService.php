<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DoctorAvailabilityService
{
    /**
     * Slot length used for grid generation and conflict checks.
     */
    public function appointmentDurationMinutes(): int
    {
        return max(1, (int) config('clinic.appointment_duration_minutes', 30));
    }

    /**
     * Persist a new weekly schedule, rejecting any window that collides with
     * another active schedule for the same doctor on the same weekday.
     *
     * @param  array{doctor_id: int, day_of_week: int, start_time: string, end_time: string, is_active?: bool}  $data
     */
    public function createSchedule(array $data): DoctorSchedule
    {
        return DB::transaction(function () use ($data): DoctorSchedule {
            $this->lockSchedulesForDoctor((int) $data['doctor_id']);

            $this->assertScheduleWindowIsFree(
                (int) $data['doctor_id'],
                (int) $data['day_of_week'],
                $data['start_time'],
                $data['end_time'],
                $data['is_active'] ?? true,
            );

            return DoctorSchedule::create([
                'doctor_id' => $data['doctor_id'],
                'day_of_week' => $data['day_of_week'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * Update an existing weekly schedule, re-checking for collisions so that
     * a narrowed or re-activated window cannot overlap a sibling schedule.
     *
     * @param  array{day_of_week?: int, start_time?: string, end_time?: string, is_active?: bool}  $data
     */
    public function updateSchedule(DoctorSchedule $schedule, array $data): DoctorSchedule
    {
        return DB::transaction(function () use ($schedule, $data): DoctorSchedule {
            $this->lockSchedulesForDoctor($schedule->doctor_id);

            $schedule->fill($data);

            $this->assertScheduleWindowIsFree(
                $schedule->doctor_id,
                (int) $schedule->day_of_week,
                $schedule->start_time,
                $schedule->end_time,
                (bool) $schedule->is_active,
                $schedule->id,
            );

            $schedule->save();

            return $schedule;
        });
    }

    /**
     * Take a row lock over the doctor's existing schedules so the overlap
     * check and the subsequent write cannot interleave with a competing
     * request. Serialised by the storage engine on MySQL/InnoDB.
     */
    private function lockSchedulesForDoctor(int $doctorId): void
    {
        DoctorSchedule::query()
            ->where('doctor_id', $doctorId)
            ->lockForUpdate()
            ->get();
    }

    /**
     * @throws ValidationException when the window is inverted, or when an active window overlaps an active sibling schedule.
     */
    private function assertScheduleWindowIsFree(
        int $doctorId,
        int $dayOfWeek,
        string $startTime,
        string $endTime,
        bool $isActive = true,
        ?int $ignoreScheduleId = null,
    ): void {
        if ($this->minutesFromMidnight($startTime) >= $this->minutesFromMidnight($endTime)) {
            throw ValidationException::withMessages([
                'end_time' => 'The end time must be after the start time.',
            ]);
        }

        if (! $isActive) {
            return;
        }

        $collides = DoctorSchedule::query()
            ->where('doctor_id', $doctorId)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->when(
                $ignoreScheduleId !== null,
                fn ($query) => $query->where('id', '!=', $ignoreScheduleId),
            )
            ->get()
            ->contains(
                fn (DoctorSchedule $other): bool => $this->timeOverlaps(
                    $startTime,
                    $endTime,
                    $other->start_time,
                    $other->end_time,
                ),
            );

        if ($collides) {
            throw ValidationException::withMessages([
                'end_time' => 'This schedule overlaps an existing active schedule for this doctor on the same day.',
            ]);
        }
    }

    /**
     * Enumerate the half-open scheduling intervals for a date, marking each
     * slot as bookable based on the weekly schedule, approved time-offs and
     * appointments that already occupy the doctor's time.
     *
     * @return array{
     *   schedules: Collection<int, array{day_of_week: int, start_time: string, end_time: string}>,
     *   is_on_time_off: bool,
     *   slot_minutes: int,
     *   slots: array<int, array{start: string, end: string, is_available: bool, is_past: bool}>
     * }
     */
    public function slotsForDate(int|User $doctor, CarbonInterface|string $date): array
    {
        $doctorId = $doctor instanceof User ? $doctor->id : (int) $doctor;
        $date = Carbon::parse($date);
        $duration = $this->appointmentDurationMinutes();

        $schedules = DoctorSchedule::query()
            ->where('doctor_id', $doctorId)
            ->where('day_of_week', $date->dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();

        $isOnTimeOff = $this->timeOffCoversDate($doctorId, $date);
        $booked = $this->bookedIntervalsOnDate($doctorId, $date);

        $slots = [];

        foreach ($schedules as $schedule) {
            $cursor = Carbon::createFromFormat('H:i', $schedule->start_time);
            $windowEnd = Carbon::createFromFormat('H:i', $schedule->end_time);

            while ($cursor->copy()->addMinutes($duration) <= $windowEnd) {
                $start = Carbon::parse($date->toDateString().' '.$cursor->format('H:i'));
                $slotEnd = $start->copy()->addMinutes($duration);

                // Slots generated for one schedule window must not be offered
                // again by an overlapping sibling window.
                $shadowedByOtherSchedule = $schedules->contains(
                    fn (DoctorSchedule $other) => $other->id !== $schedule->id &&
                        $this->timeOverlaps($other->start_time, $other->end_time, $cursor->format('H:i'), $slotEnd->format('H:i'))
                );

                $slots[] = [
                    'start' => $start->format('H:i'),
                    'end' => $slotEnd->format('H:i'),
                    'is_available' => ! $isOnTimeOff
                        && ! $shadowedByOtherSchedule
                        && $start->isFuture()
                        && ! $this->intervalOverlapsBooked($start, $slotEnd, $booked),
                    'is_past' => $start->isPast(),
                ];

                $cursor->addMinutes($duration);
            }
        }

        return [
            'schedules' => $schedules->map(
                fn (DoctorSchedule $schedule) => [
                    'day_of_week' => $schedule->day_of_week,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                ],
            )->values(),
            'is_on_time_off' => $isOnTimeOff,
            'slot_minutes' => $duration,
            'slots' => $slots,
        ];
    }

    /**
     * Check if two `H:i` windows overlap using half-open [start, end) intervals.
     */
    private function timeOverlaps(string $start1, string $end1, string $start2, string $end2): bool
    {
        return $this->minutesFromMidnight($start1) < $this->minutesFromMidnight($end2)
            && $this->minutesFromMidnight($end1) > $this->minutesFromMidnight($start2);
    }

    /**
     * Minutes elapsed since midnight for an `H:i` value, so window comparisons
     * never depend on the current time, timezone or database-specific time functions.
     */
    private function minutesFromMidnight(string $time): int
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', $time));

        return ($hours * 60) + $minutes;
    }

    /**
     * Check if the given timestamp is a valid, bookable appointment start.
     */
    public function slotIsAvailable(int $doctorId, CarbonInterface|string $scheduledAt, ?int $ignoreAppointmentId = null): bool
    {
        return $this->unavailabilityReason($doctorId, $scheduledAt, $ignoreAppointmentId) === null;
    }

    /**
     * Validate a candidate appointment start, returning a human-readable
     * reason why it cannot be scheduled, or null when it is available.
     */
    public function unavailabilityReason(int $doctorId, CarbonInterface|string $scheduledAt, ?int $ignoreAppointmentId = null): ?string
    {
        $start = Carbon::parse($scheduledAt)->setSeconds(0);
        $duration = $this->appointmentDurationMinutes();

        if ($start->isPast()) {
            return 'Appointments cannot be scheduled in the past.';
        }

        if ($start->minute % $duration !== 0) {
            return 'The appointment must start on a slot boundary.';
        }

        $slotStart = $start->format('H:i');
        $slotEnd = $start->copy()->addMinutes($duration)->format('H:i');

        $hasWindow = DoctorSchedule::query()
            ->where('doctor_id', $doctorId)
            ->where('day_of_week', $start->dayOfWeek)
            ->where('is_active', true)
            ->get()
            ->contains(
                fn (DoctorSchedule $schedule): bool => $schedule->start_time <= $slotStart && $schedule->end_time >= $slotEnd,
            );

        if (! $hasWindow) {
            return "The selected time is outside the doctor's working hours.";
        }

        if ($this->timeOffCoversDate($doctorId, $start->copy()->startOfDay())) {
            return 'The doctor is on approved time-off on this date.';
        }

        $booked = $this->bookedIntervalsOnDate($doctorId, $start->copy()->startOfDay(), $ignoreAppointmentId);
        $slotEndCarbon = $start->copy()->addMinutes($duration);

        if ($this->intervalOverlapsBooked($start, $slotEndCarbon, $booked)) {
            return 'This time slot has already been booked.';
        }

        return null;
    }

    /**
     * Abort the request when the timestamp cannot be booked.
     */
    public function assertSlotAvailable(int $doctorId, CarbonInterface|string $scheduledAt, ?int $ignoreAppointmentId = null): void
    {
        $reason = $this->unavailabilityReason($doctorId, $scheduledAt, $ignoreAppointmentId);

        if ($reason !== null) {
            abort(422, $reason);
        }
    }

    /**
     * Whether an approved time-off covers the given calendar date.
     */
    public function timeOffCoversDate(int $doctorId, CarbonInterface $date): bool
    {
        return DoctorTimeOff::query()
            ->where('doctor_id', $doctorId)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->exists();
    }

    /**
     * The occupancy intervals produced by a doctor's active appointments on a date.
     *
     * @return Collection<int, array{start: Carbon, end: Carbon}>
     */
    private function bookedIntervalsOnDate(int $doctorId, CarbonInterface $date, ?int $ignoreAppointmentId = null): Collection
    {
        $duration = $this->appointmentDurationMinutes();

        return Appointment::query()
            ->where('doctor_id', $doctorId)
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->whereBetween('scheduled_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->when(
                $ignoreAppointmentId !== null,
                fn ($query) => $query->where('id', '!=', $ignoreAppointmentId),
            )
            ->get()
            ->map(
                fn (Appointment $appointment) => [
                    'start' => $appointment->scheduled_at->copy()->setSeconds(0),
                    'end' => $appointment->scheduled_at->copy()->setSeconds(0)->addMinutes($duration),
                ],
            );
    }

    /**
     * True when the half-open [start, end) interval collides with a booking.
     *
     * @param  Collection<int, array{start: Carbon, end: Carbon}>  $booked
     */
    private function intervalOverlapsBooked(Carbon $start, Carbon $end, Collection $booked): bool
    {
        return $booked->contains(
            fn (array $interval): bool => $interval['start'] < $end && $interval['end'] > $start,
        );
    }
}
