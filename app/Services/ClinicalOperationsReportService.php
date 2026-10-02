<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Encounter;
use App\Models\InventoryBatch;
use App\Models\LabOrder;
use App\Models\Lead;
use App\Models\QueueEntry;
use App\Models\StockAlert;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Operational reports that DESIGN §16 calls for but that the initial analytics
 * set did not cover: lab turnaround, patient wait time, lead conversion and
 * batch expiry.
 *
 * Every figure is computed from real rows (no stubbed or hardcoded values), and
 * each method reports the sample size it is based on so a dashboard can be read
 * honestly when a period had too little data to be meaningful.
 *
 * The date-difference expressions are written twice because SQLite and MySQL
 * have no shared function for elapsed minutes: `julianday` on SQLite,
 * `TIMESTAMPDIFF` on MySQL. Both produce whole minutes.
 */
class ClinicalOperationsReportService
{
    /**
     * Elapsed whole minutes between two timestamp columns, in a way that works
     * on both supported drivers.
     *
     * SQLite uses `strftime('%s', …)`, which returns an exact integer Unix
     * timestamp, rather than `julianday`: julian day numbers are around 2.4
     * million, so subtracting two of them in a double loses enough precision
     * to shift a minute boundary and report a 10-minute wait as 9.
     *
     * @param  string  $start  Column holding the earlier timestamp.
     * @param  string  $end  Column holding the later timestamp.
     */
    private function minutesBetween(string $start, string $end): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "CAST((strftime('%s', {$end}) - strftime('%s', {$start})) / 60 AS INTEGER)"
            : "TIMESTAMPDIFF(MINUTE, {$start}, {$end})";
    }

    /**
     * Summarise a set of elapsed-minute values.
     *
     * @param  Collection<int, float|int|string>  $minutes
     * @return array{sample_size: int, average_minutes: float|null, p50_minutes: float|null, p90_minutes: float|null, p95_minutes: float|null, max_minutes: float|null}
     */
    private function summarize(Collection $minutes): array
    {
        $clean = $minutes
            ->map(fn ($value): float => (float) $value)
            ->filter(fn (float $value): bool => $value >= 0)
            ->sort()
            ->values();

        $count = $clean->count();

        if ($count === 0) {
            return [
                'sample_size' => 0,
                'average_minutes' => null,
                'p50_minutes' => null,
                'p90_minutes' => null,
                'p95_minutes' => null,
                'max_minutes' => null,
            ];
        }

        return [
            'sample_size' => $count,
            'average_minutes' => round((float) $clean->avg(), 1),
            'p50_minutes' => $this->percentile($clean, 50.0),
            'p90_minutes' => $this->percentile($clean, 90.0),
            'p95_minutes' => $this->percentile($clean, 95.0),
            'max_minutes' => (float) $clean->max(),
        ];
    }

    /**
     * Nearest-rank percentile over an ascending, zero-indexed collection.
     *
     * @param  Collection<int, float>  $sorted
     */
    private function percentile(Collection $sorted, float $percentile): float
    {
        $count = $sorted->count();

        if ($count === 0) {
            return 0.0;
        }

        $rank = (int) ceil(($percentile / 100) * $count);

        return (float) $sorted->get(max(0, min($count - 1, $rank - 1)));
    }

    /**
     * Laboratory turnaround: ordered -> sample collected -> result recorded ->
     * verified/finalized, broken down by test type (DESIGN §16 "lab turnaround").
     *
     * Orders that have not reached a given milestone are excluded from that
     * milestone's figures rather than counted as a very long wait, so an
     * in-flight order can never flatter or distort the averages.
     *
     * @return array<string, mixed>
     */
    public function labTurnover(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $toSample = $this->minutesBetween('created_at', 'sample_collected_at');
        $toResult = $this->minutesBetween('sample_collected_at', 'recorded_at');
        $toVerified = $this->minutesBetween('recorded_at', 'verified_at');
        $total = $this->minutesBetween('created_at', 'verified_at');

        $orders = LabOrder::query()
            ->where('status', '!=', LabOrder::STATUS_CANCELLED)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->select('test_name')
            ->selectRaw("{$toSample} as order_to_sample_minutes")
            ->selectRaw("{$toResult} as sample_to_result_minutes")
            ->selectRaw("{$toVerified} as result_to_verification_minutes")
            ->selectRaw("{$total} as order_to_verification_minutes")
            ->get();

        $rows = $orders
            ->map(fn (LabOrder $order): array => [
                'test_name' => $order->test_name,
                'order_to_sample_minutes' => $order->order_to_sample_minutes,
                'sample_to_result_minutes' => $order->sample_to_result_minutes,
                'result_to_verification_minutes' => $order->result_to_verification_minutes,
                'order_to_verification_minutes' => $order->order_to_verification_minutes,
            ])
            ->values();

        $byTest = $rows
            ->groupBy('test_name')
            ->map(function (Collection $group, string $testName): array {
                return [
                    'test_name' => $testName,
                    'order_count' => $group->count(),
                    'order_to_sample' => $this->summarize($group->pluck('order_to_sample_minutes')->filter()->values()),
                    'sample_to_result' => $this->summarize($group->pluck('sample_to_result_minutes')->filter()->values()),
                    'result_to_verification' => $this->summarize($group->pluck('result_to_verification_minutes')->filter()->values()),
                    'order_to_verification' => $this->summarize($group->pluck('order_to_verification_minutes')->filter()->values()),
                ];
            })
            ->sortByDesc('order_count')
            ->values()
            ->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'order_count' => $rows->count(),
                'order_to_sample' => $this->summarize($rows->pluck('order_to_sample_minutes')->filter()->values()),
                'sample_to_result' => $this->summarize($rows->pluck('sample_to_result_minutes')->filter()->values()),
                'result_to_verification' => $this->summarize($rows->pluck('result_to_verification_minutes')->filter()->values()),
                'order_to_verification' => $this->summarize($rows->pluck('order_to_verification_minutes')->filter()->values()),
            ],
            'by_test' => $byTest,
        ];
    }

    /**
     * Patient wait time: queue check-in -> encounter start, by doctor, by
     * branch and by day (DESIGN §16 "wait times").
     *
     * A queue entry only produces a wait once its appointment has an encounter,
     * which is the point the patient actually began being seen.
     *
     * @return array<string, mixed>
     */
    public function waitTimes(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $expression = $this->minutesBetween('queue_entries.checked_in_at', 'encounters.created_at');

        // The doctor and branch names are resolved in PHP from eager-loaded
        // relations rather than joined into the aggregate query: the join is
        // against queue_entries, and QueueEntry carries a branch global scope
        // that qualifies its own `users` reference, so a raw `users as doctors`
        // join resolves to nothing. Only the elapsed time needs SQL.
        $entries = QueueEntry::query()
            ->join('encounters', 'encounters.appointment_id', '=', 'queue_entries.appointment_id')
            ->where('queue_entries.status', '!=', QueueEntry::STATUS_CANCELLED)
            ->whereBetween('queue_entries.checked_in_at', [$from, $to])
            ->whereNotNull('queue_entries.checked_in_at')
            ->whereNotNull('encounters.appointment_id')
            ->select('queue_entries.id', 'queue_entries.appointment_id', 'queue_entries.branch_id', 'queue_entries.checked_in_at')
            ->selectRaw('encounters.doctor_id as encounter_doctor_id')
            ->selectRaw("{$expression} as wait_minutes")
            ->get();

        $encounters = Encounter::query()
            ->whereIn('appointment_id', $entries->pluck('appointment_id')->filter()->unique())
            ->get(['id', 'appointment_id', 'doctor_id'])
            ->keyBy('appointment_id');

        $branchNames = Branch::query()->pluck('name', 'id');
        $doctorNames = User::query()->pluck('name', 'id');

        $rows = $entries
            ->map(function ($entry) use ($encounters, $branchNames, $doctorNames): ?array {
                $encounter = $encounters->get($entry->appointment_id);

                if ($encounter === null) {
                    return null;
                }

                $doctorId = $encounter->doctor_id === null ? null : (int) $encounter->doctor_id;
                $branchId = $entry->branch_id === null ? null : (int) $entry->branch_id;

                return [
                    'doctor_id' => $doctorId,
                    'doctor_name' => $doctorId === null ? 'Unknown' : ($doctorNames->get($doctorId) ?? 'Unknown'),
                    'branch_id' => $branchId,
                    'branch_name' => $branchId === null ? 'Unknown' : ($branchNames->get($branchId) ?? 'Unknown'),
                    'date' => substr((string) $entry->checked_in_at, 0, 10),
                    'wait_minutes' => (int) $entry->wait_minutes,
                ];
            })
            ->filter()
            ->values();

        // A negative wait means the encounter row predates the check-in stamp,
        // which is a data-entry problem rather than a real wait; those rows are
        // dropped rather than reported as a negative average.
        $usable = $rows->filter(fn (array $row): bool => $row['wait_minutes'] >= 0)->values();

        $groupSummary = fn (Collection $group): array => $this->summarize($group->pluck('wait_minutes')->values());

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $groupSummary($usable),
            'excluded_negative_rows' => $rows->count() - $usable->count(),
            'by_doctor' => $usable
                ->groupBy('doctor_id')
                ->map(fn (Collection $group): array => [
                    'doctor_id' => $group->first()['doctor_id'],
                    'doctor_name' => $group->first()['doctor_name'],
                ] + $groupSummary($group))
                ->sortByDesc('sample_size')
                ->values()
                ->all(),
            'by_branch' => $usable
                ->groupBy('branch_id')
                ->map(fn (Collection $group): array => [
                    'branch_id' => $group->first()['branch_id'],
                    'branch_name' => $group->first()['branch_name'],
                ] + $groupSummary($group))
                ->sortByDesc('sample_size')
                ->values()
                ->all(),
            'by_day' => $usable
                ->groupBy('date')
                ->map(fn (Collection $group, string $date): array => ['date' => $date] + $groupSummary($group))
                ->sortBy('date')
                ->values()
                ->all(),
        ];
    }

    /**
     * Lead conversion: lead -> booked appointment -> completed visit, by source
     * (DESIGN §16 "lead conversion").
     *
     * A lead is linked to the patient it converted into by name and phone
     * number, because the CRM and the patient register are separate records
     * until the lead is converted. Matching is exact on phone, or on name when
     * no phone is recorded, and only counts appointments created after the lead
     * so an existing patient re-enquiry is not scored as a conversion.
     *
     * @return array<string, mixed>
     */
    public function leadConversion(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $leads = Lead::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get();

        $rows = $leads->map(function (Lead $lead): array {
            $appointments = $this->appointmentsForLead($lead);

            return [
                'lead_id' => $lead->id,
                'source' => $lead->source ?? Lead::SOURCE_OTHER,
                'status' => $lead->status ?? Lead::STATUS_NEW,
                'created_at' => $lead->created_at,
                'appointment_count' => $appointments->count(),
                'booked' => $appointments->isNotEmpty(),
                'completed' => $appointments->contains(
                    fn ($appointment): bool => in_array($appointment->status, ['completed'], true),
                ),
            ];
        })->values();

        $summarize = function (Collection $group): array {
            $total = $group->count();
            $booked = $group->where('booked', true)->count();
            $completed = $group->where('completed', true)->count();

            return [
                'lead_count' => $total,
                'booked_count' => $booked,
                'completed_count' => $completed,
                'booking_rate_pct' => $total === 0 ? 0.0 : round($booked / $total * 100, 1),
                'completion_rate_pct' => $total === 0 ? 0.0 : round($completed / $total * 100, 1),
            ];
        };

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $summarize($rows),
            'by_source' => $rows
                ->groupBy('source')
                ->map(fn (Collection $group, string $source): array => ['source' => $source] + $summarize($group))
                ->sortByDesc('lead_count')
                ->values()
                ->all(),
            'by_status' => $rows
                ->groupBy('status')
                ->map(fn (Collection $group, string $status): array => ['status' => $status] + $summarize($group))
                ->sortByDesc('lead_count')
                ->values()
                ->all(),
        ];
    }

    /**
     * Appointments attributable to a lead, ignoring any that predate it.
     *
     * @return Collection<int, Appointment>
     */
    private function appointmentsForLead(Lead $lead): Collection
    {
        if (blank($lead->phone) && blank($lead->name)) {
            return collect();
        }

        return Appointment::query()
            ->join('patients', 'patients.id', '=', 'appointments.patient_id')
            ->when(filled($lead->phone), fn ($query) => $query->where('patients.phone', $lead->phone))
            ->when(blank($lead->phone), fn ($query) => $query->where('patients.name', $lead->name))
            ->where('appointments.created_at', '>=', $lead->created_at)
            ->select('appointments.*')
            ->get();
    }

    /**
     * Batch expiry exposure (DESIGN §16 "pharmacy stock/expiry").
     *
     * This is a report over live batches, not a replay of the `stock_alerts`
     * rows the nightly scan writes: the scan is a notification, this is the
     * inventory position. A batch with no expiry date on file is reported
     * separately rather than being treated as expired.
     *
     * @return array<string, mixed>
     */
    public function expiryExposure(CarbonInterface $asOf, int $withinDays = 90): array
    {
        $asOf = $asOf->copy()->startOfDay();
        $horizon = $asOf->copy()->addDays($withinDays);

        $batches = InventoryBatch::query()
            // inventory_batches has no branch_id of its own; a batch belongs to
            // the branch of the item it was purchased for. Scoping the join
            // through the item's branch_id is what keeps a multi-branch clinic
            // from seeing another branch's stock value. (BranchScope on the
            // eager-loaded relation would not apply: an explicit column list
            // bypasses the relation's global scope.)
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_batches.inventory_item_id')
            ->when(
                ! BranchContext::bypassed() && BranchContext::id() !== null,
                fn ($query) => $query->where('inventory_items.branch_id', BranchContext::id()),
            )
            ->select('inventory_batches.*')
            ->with('item:id,item_name,sku,unit_price,reorder_level,branch_id')
            ->where('inventory_batches.current_quantity', '>', 0)
            ->get();

        $row = function (InventoryBatch $batch) use ($asOf): array {
            $expiry = $batch->expiry_date;

            // Positive means "still valid for N days"; negative means already
            // expired. Carbon's diffInDays is signed from the receiver towards
            // the argument, so measure from today to the expiry date.
            $days = $expiry === null
                ? null
                : (int) $asOf->copy()->startOfDay()->diffInDays($expiry->copy()->startOfDay(), false);

            return [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'item_name' => $batch->item?->item_name ?? 'Unknown',
                'item_sku' => $batch->item?->sku ?? null,
                'unit_price' => (float) ($batch->item?->unit_price ?? 0),
                'current_quantity' => (float) $batch->current_quantity,
                'expiry_date' => $expiry?->toDateString(),
                'days_until_expiry' => $days,
                'stock_value' => round((float) $batch->current_quantity * (float) ($batch->item?->unit_price ?? 0), 2),
                'has_open_alert' => StockAlert::query()
                    ->where('inventory_batch_id', $batch->id)
                    ->exists(),
            ];
        };

        $withExpiry = $batches->filter(fn (InventoryBatch $batch): bool => $batch->expiry_date !== null)->map($row)->values();
        $withoutExpiry = $batches->filter(fn (InventoryBatch $batch): bool => $batch->expiry_date === null)->map($row)->values();

        $expired = $withExpiry->filter(fn (array $r): bool => $r['days_until_expiry'] < 0)->sortBy('days_until_expiry')->values();
        $expiring = $withExpiry
            ->filter(fn (array $r): bool => $r['days_until_expiry'] >= 0 && $r['days_until_expiry'] <= $withinDays)
            ->sortBy('days_until_expiry')
            ->values();

        return [
            'as_of' => $asOf->toDateString(),
            'within_days' => $withinDays,
            'currency' => $this->currency(),
            'summary' => [
                'expired_batch_count' => $expired->count(),
                'expired_stock_value' => round((float) $expired->sum('stock_value'), 2),
                'expiring_batch_count' => $expiring->count(),
                'expiring_stock_value' => round((float) $expiring->sum('stock_value'), 2),
                'at_risk_stock_value' => round((float) $expired->sum('stock_value') + (float) $expiring->sum('stock_value'), 2),
                'no_expiry_date_batch_count' => $withoutExpiry->count(),
                'no_expiry_date_stock_value' => round((float) $withoutExpiry->sum('stock_value'), 2),
            ],
            'expired' => $expired->all(),
            'expiring' => $expiring->all(),
            'no_expiry_date' => $withoutExpiry->all(),
        ];
    }

    /**
     * CSV export of the expiry report, for the same filters the endpoint uses.
     */
    public function expiryExportCsv(CarbonInterface $asOf, int $withinDays = 90): string
    {
        $report = $this->expiryExposure($asOf, $withinDays);

        $rows = [
            ['batch_number', 'item_name', 'item_sku', 'current_quantity', 'expiry_date', 'days_until_expiry', 'stock_value', 'currency', 'bucket'],
        ];

        $bucket = fn (array $r): string => $r['expiry_date'] === null
            ? 'no_expiry_date'
            : ($r['days_until_expiry'] < 0 ? 'expired' : 'expiring');

        foreach (array_merge($report['expired'], $report['expiring'], $report['no_expiry_date']) as $r) {
            $rows[] = [
                $r['batch_number'],
                $r['item_name'],
                (string) ($r['item_sku'] ?? ''),
                (string) $r['current_quantity'],
                (string) ($r['expiry_date'] ?? ''),
                (string) ($r['days_until_expiry'] ?? ''),
                (string) $r['stock_value'],
                (string) $report['currency'],
                $bucket($r),
            ];
        }

        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function currency(): string
    {
        $code = (string) config('clinic.currency', 'PKR');

        return $code === '' ? 'PKR' : strtoupper($code);
    }
}
