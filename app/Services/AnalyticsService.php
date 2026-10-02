<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Claim;
use App\Models\DoctorSchedule;
use App\Models\Encounter;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /**
     * Statuses that represent an active (non-cancelled) appointment.
     *
     * @var array<int, string>
     */
    private const ACTIVE_STATUSES = [
        'booked', 'confirmed', 'arrived', 'waiting', 'in_consultation', 'completed',
    ];

    /**
     * Number of days ahead to project revenue.
     */
    private const FORECAST_HORIZON_DAYS = 30;

    public function __construct(private readonly DoctorAvailabilityService $availability) {}

    /**
     * Revenue trends, payment-method and insurance-carrier breakdowns, and a
     * linear-regression forecast over a configurable date range.
     *
     * @return array<string, mixed>
     */
    public function revenueReport(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $payments = Payment::query()
            ->with('invoice')
            ->whereBetween('paid_at', [$from, $to])
            ->orderBy('paid_at')
            ->get();

        $total = (float) $payments->sum('amount');
        $daily = $this->dailyTotals($from, $to, $payments);

        $byCarrier = Claim::query()
            ->join('payments', 'payments.invoice_id', '=', 'claims.invoice_id')
            ->join('insurance_providers', 'insurance_providers.id', '=', 'claims.insurance_provider_id')
            ->whereBetween('payments.paid_at', [$from, $to])
            ->whereNull('claims.deleted_at')
            ->groupBy('insurance_providers.id', 'insurance_providers.name')
            ->selectRaw('insurance_providers.name as carrier, SUM(payments.amount) as total')
            ->get()
            ->map(fn ($row): array => [
                'carrier' => $row->carrier,
                'total' => round((float) $row->total, 2),
            ])
            ->sortByDesc('total')
            ->values();

        $forecast = $this->forecast(array_column($daily, 'total'));

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'total_revenue' => round($total, 2),
            'payment_count' => $payments->count(),
            'daily' => $daily,
            'by_payment_method' => $payments
                ->groupBy('method')
                ->map(fn (Collection $group): array => [
                    'method' => (string) $group->first()->method,
                    'total' => round((float) $group->sum('amount'), 2),
                ])
                ->sortByDesc('total')
                ->values(),
            'by_insurance_carrier' => $byCarrier,
            'forecast' => $forecast,
        ];
    }

    /**
     * Per-doctor utilization (booked vs. available capacity), consultation
     * duration estimates and a day-of-week × hour heatmap over the range.
     *
     * @return array<string, mixed>
     */
    public function utilizationReport(CarbonInterface $from, CarbonInterface $to, ?int $doctorId = null): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $query = User::query()->where('role', 'Doctor')->orderBy('name');

        if ($doctorId !== null) {
            $query->where('id', $doctorId);
        }

        $doctors = $query->get(['id', 'name']);

        $appointments = Appointment::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereBetween('scheduled_at', [$from, $to])
            ->get();

        $bookedByDoctor = $appointments->groupBy('doctor_id');
        $weekdayCounts = $this->weekdayCounts($from, $to);
        $slotMinutes = $this->availability->appointmentDurationMinutes();

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'slot_minutes' => $slotMinutes,
            'doctors' => $doctors->map(function (User $doctor) use ($bookedByDoctor, $weekdayCounts, $slotMinutes, $from, $to): array {
                $doctorAppointments = $bookedByDoctor->get($doctor->id, new Collection);

                $capacity = $this->availableCapacity($doctor, $weekdayCounts, $slotMinutes);
                $bookedCount = $doctorAppointments->count();

                return [
                    'doctor_id' => $doctor->id,
                    'doctor_name' => $doctor->name,
                    'booked_slots' => $bookedCount,
                    'available_slots' => $capacity,
                    'utilization_pct' => $capacity > 0 ? round(($bookedCount / $capacity) * 100, 2) : 0.0,
                    'average_consultation_minutes' => $this->averageConsultationMinutes($doctor->id, $from, $to, $slotMinutes),
                    'heatmap' => $this->heatmap($doctorAppointments),
                ];
            })->values(),
        ];
    }

    /**
     * Appointment status conversion funnel for the range.
     *
     * @return array<int, array{status: string, count: int}>
     */
    public function conversionFunnel(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $rows = Appointment::query()
            ->whereIn('status', array_merge(self::ACTIVE_STATUSES, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW]))
            ->whereBetween('scheduled_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $cancelled = (int) ($rows[Appointment::STATUS_CANCELLED] ?? 0);

        // Funnel steps are cumulative: each row counts everything that reached
        // that state or beyond, so the numbers form a monotonically
        // decreasing series across the PRD §6 lifecycle.
        return [
            ['status' => Appointment::STATUS_BOOKED, 'count' => $this->countFrom($rows, [
                Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED, Appointment::STATUS_ARRIVED,
                Appointment::STATUS_WAITING, Appointment::STATUS_IN_CONSULTATION, Appointment::STATUS_COMPLETED,
            ])],
            ['status' => Appointment::STATUS_CONFIRMED, 'count' => $this->countFrom($rows, [
                Appointment::STATUS_CONFIRMED, Appointment::STATUS_ARRIVED,
                Appointment::STATUS_WAITING, Appointment::STATUS_IN_CONSULTATION, Appointment::STATUS_COMPLETED,
            ])],
            ['status' => Appointment::STATUS_ARRIVED, 'count' => $this->countFrom($rows, [
                Appointment::STATUS_ARRIVED, Appointment::STATUS_WAITING,
                Appointment::STATUS_IN_CONSULTATION, Appointment::STATUS_COMPLETED,
            ])],
            ['status' => Appointment::STATUS_COMPLETED, 'count' => $this->countFrom($rows, [Appointment::STATUS_COMPLETED])],
            ['status' => Appointment::STATUS_CANCELLED, 'count' => $cancelled],
            ['status' => Appointment::STATUS_NO_SHOW, 'count' => (int) ($rows[Appointment::STATUS_NO_SHOW] ?? 0)],
        ];
    }

    /**
     * Visit volumes per branch over the range.
     *
     * @return array<int, array{branch_id: int|null, branch_name: string, visit_count: int, unique_patients: int}>
     */
    public function branchVisitVolumes(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        return Appointment::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereBetween('scheduled_at', [$from, $to])
            ->with('branch:id,name')
            ->get()
            ->groupBy(fn (Appointment $appointment): int => (int) $appointment->branch_id)
            ->map(function (Collection $appointments): array {
                $branch = $appointments->first()->branch;

                return [
                    'branch_id' => (int) $appointments->first()->branch_id,
                    'branch_name' => $branch?->name ?? 'Unknown',
                    'visit_count' => $appointments->count(),
                    'unique_patients' => $appointments->pluck('patient_id')->unique()->count(),
                ];
            })
            ->sortByDesc('visit_count')
            ->values()
            ->all();
    }

    /**
     * Daily revenue series, keeping every calendar day in the range present.
     *
     * @param  Collection<int, Payment>  $payments
     * @return array<int, array{date: string, total: float, by_method: array<string, float>}>
     */
    private function dailyTotals(CarbonInterface $from, CarbonInterface $to, Collection $payments): array
    {
        $daily = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $date = $cursor->toDateString();
            $dayPayments = $payments->filter(
                fn (Payment $payment): bool => $payment->paid_at?->toDateString() === $date,
            );

            $byMethod = $dayPayments->groupBy('method')
                ->map(fn (Collection $group): float => round((float) $group->sum('amount'), 2));

            $daily[] = [
                'date' => $date,
                'total' => round((float) $dayPayments->sum('amount'), 2),
                'by_method' => $byMethod->isEmpty() ? new \stdClass : $byMethod->all(),
            ];

            $cursor->addDay();
        }

        return $daily;
    }

    /**
     * A simple linear-regression forecast of daily revenue for the next month.
     *
     * @param  array<int, float>  $dailyTotals
     * @return array<string, mixed>
     */
    private function forecast(array $dailyTotals): array
    {
        $count = count($dailyTotals);

        if ($count === 0) {
            return [
                'method' => 'linear-regression',
                'horizon_days' => self::FORECAST_HORIZON_DAYS,
                'next_30_days_total' => 0.0,
                'daily' => [],
            ];
        }

        $xSum = $count * ($count - 1) / 2;
        $ySum = array_sum($dailyTotals);
        $xySum = 0.0;
        $xxSum = 0.0;

        foreach ($dailyTotals as $index => $value) {
            $xySum += $index * $value;
            $xxSum += $index * $index;
        }

        $denominator = $count * $xxSum - $xSum ** 2;

        if (abs($denominator) < PHP_FLOAT_EPSILON) {
            $slope = 0.0;
            $intercept = $count > 0 ? $ySum / $count : 0.0;
        } else {
            $slope = ($count * $xySum - $xSum * $ySum) / $denominator;
            $intercept = ($ySum - $slope * $xSum) / $count;
        }

        $daily = [];
        $projectedTotal = 0.0;

        for ($i = 1; $i <= self::FORECAST_HORIZON_DAYS; $i++) {
            $predicted = max(0.0, $intercept + $slope * ($count - 1 + $i));
            $daily[] = ['projected_day' => $i, 'predicted_revenue' => round($predicted, 2)];
            $projectedTotal += $predicted;
        }

        return [
            'method' => 'linear-regression',
            'horizon_days' => self::FORECAST_HORIZON_DAYS,
            'next_30_days_total' => round($projectedTotal, 2),
            'daily' => $daily,
        ];
    }

    /**
     * Total bookable slot starts in the range for one doctor's active schedule.
     *
     * @param  array<int, int>  $weekdayCounts
     */
    private function availableCapacity(User $doctor, array $weekdayCounts, int $slotMinutes): int
    {
        $schedules = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('is_active', true)
            ->get();

        $capacity = 0;

        foreach ($schedules as $schedule) {
            $cursor = Carbon::createFromFormat('H:i', $schedule->start_time);
            $windowEnd = Carbon::createFromFormat('H:i', $schedule->end_time);
            $slotsPerDay = 0;

            while ($cursor->copy()->addMinutes($slotMinutes) <= $windowEnd) {
                $slotsPerDay++;
                $cursor->addMinutes($slotMinutes);
            }

            $capacity += $slotsPerDay * $weekdayCounts[$schedule->day_of_week];
        }

        return $capacity;
    }

    /**
     * @return array<int, int> Day-of-week counts keyed 0 (Sunday) to 6 (Saturday).
     */
    private function weekdayCounts(CarbonInterface $from, CarbonInterface $to): array
    {
        $counts = array_fill(0, 7, 0);
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $counts[$cursor->dayOfWeek]++;
            $cursor->addDay();
        }

        return $counts;
    }

    /**
     * Truck the doctor's booked appointments into day-of-week × hour buckets.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return array<int, array{day_of_week: int, hour: int, count: int}>
     */
    private function heatmap(Collection $appointments): array
    {
        $buckets = [];

        foreach ($appointments->groupBy(fn (Appointment $appointment): int => $appointment->scheduled_at->dayOfWeek) as $dayOfWeek => $dayGroup) {
            foreach ($dayGroup->groupBy(fn (Appointment $appointment): int => (int) $appointment->scheduled_at->format('H')) as $hour => $hourGroup) {
                $buckets[] = [
                    'day_of_week' => (int) $dayOfWeek,
                    'hour' => (int) $hour,
                    'count' => $hourGroup->count(),
                ];
            }
        }

        return $buckets;
    }

    /**
     * Average minutes from the scheduled appointment to its recorded encounter.
     *
     * Falls back to the planned slot length, reported via the sample size, when
     * no encounters exist in the window.
     *
     * @return array{minutes: float, sample_size: int, source: string}
     */
    private function averageConsultationMinutes(
        int $doctorId,
        CarbonInterface $from,
        CarbonInterface $to,
        int $slotMinutes,
    ): array {
        $gapExpression = DB::getDriverName() === 'sqlite'
            ? 'CAST((julianday(encounters.created_at) - julianday(appointments.scheduled_at)) * 24 * 60 AS INTEGER)'
            : 'TIMESTAMPDIFF(MINUTE, appointments.scheduled_at, encounters.created_at)';

        $gaps = Encounter::query()
            ->join('appointments', 'appointments.id', '=', 'encounters.appointment_id')
            ->where('encounters.doctor_id', $doctorId)
            ->whereBetween('appointments.scheduled_at', [$from, $to])
            ->whereNotNull('encounters.appointment_id')
            ->selectRaw("{$gapExpression} as gap_minutes")
            ->get()
            ->pluck('gap_minutes')
            ->filter(fn ($gap): bool => is_numeric($gap) && (int) $gap >= 0 && (int) $gap <= 480)
            ->values();

        if ($gaps->count() === 0) {
            return ['minutes' => (float) $slotMinutes, 'sample_size' => 0, 'source' => 'planned-slot'];
        }

        return [
            'minutes' => round((float) $gaps->avg(), 2),
            'sample_size' => $gaps->count(),
            'source' => 'encounter-timing',
        ];
    }

    /**
     * @param  Collection<string, int>  $rows
     * @param  array<int, string>  $statuses
     */
    private function countFrom(Collection $rows, array $statuses): int
    {
        return (int) $rows->only($statuses)->sum();
    }
}
