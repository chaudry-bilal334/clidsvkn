<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\Payment;
use App\Services\AnalyticsService;
use App\Services\BranchContext;
use App\Services\ClinicalOperationsReportService;
use App\Services\QueryCacheService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly QueryCacheService $queryCache,
        private readonly ClinicalOperationsReportService $operations,
    ) {}

    /**
     * Laboratory turnaround: ordered -> sample collected -> result -> verified,
     * with averages and percentiles per test type (DESIGN §16).
     */
    public function labTurnaround(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json([
            'success' => true,
            'data' => $this->operations->labTurnover($from, $to),
        ]);
    }

    /**
     * Patient wait time: queue check-in -> encounter start, by doctor, branch
     * and day (DESIGN §16).
     */
    public function waitTimes(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json([
            'success' => true,
            'data' => $this->operations->waitTimes($from, $to),
        ]);
    }

    /**
     * Lead conversion: lead -> booked appointment -> completed visit, by source
     * (DESIGN §16).
     */
    public function leadConversion(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json([
            'success' => true,
            'data' => $this->operations->leadConversion($from, $to),
        ]);
    }

    /**
     * Batch expiry exposure with stock value at risk (DESIGN §16 "pharmacy
     * stock/expiry"). Complements the operational `inventory/expiry-alerts`
     * endpoint: this is the inventory position, not the alert feed.
     */
    public function expiry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'within_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $asOf = isset($validated['as_of']) ? Carbon::parse($validated['as_of']) : now();

        return response()->json([
            'success' => true,
            'data' => $this->operations->expiryExposure($asOf, (int) ($validated['within_days'] ?? 90)),
        ]);
    }

    public function exportExpiry(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'within_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $asOf = isset($validated['as_of']) ? Carbon::parse($validated['as_of']) : now();
        $csv = $this->operations->expiryExportCsv($asOf, (int) ($validated['within_days'] ?? 90));

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, 'expiry-report-'.$asOf->toDateString().'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Resolve and validate the shared `from` / `to` query parameters.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $from = $request->input('from', now()->subDays(29)->toDateString());
        $to = $request->input('to', now()->toDateString());

        $this->validateDates([
            'from' => $from,
            'to' => $to,
        ]);

        return [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
    }

    public function revenueAnalytics(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->subDays(29)->toDateString());
        $to = $request->input('to', now()->toDateString());

        $this->validateDates([
            'from' => $from,
            'to' => $to,
        ]);

        $revenue = $this->analytics->revenueReport(
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        );

        return response()->json([
            'success' => true,
            'data' => $revenue,
            'meta' => ['currency' => $this->currency()],
        ]);
    }

    public function utilizationAnalytics(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->subDays(29)->toDateString());
        $to = $request->input('to', now()->toDateString());

        $this->validateDates([
            'from' => $from,
            'to' => $to,
        ]);

        $validated = $request->validate([
            'doctor_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $utilization = $this->analytics->utilizationReport(
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
            $validated['doctor_id'] ?? null,
        );

        return response()->json([
            'success' => true,
            'data' => $utilization,
        ]);
    }

    public function conversionAnalytics(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        $funnel = $this->analytics->conversionFunnel($from, $to);

        return response()->json([
            'success' => true,
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'funnel' => $funnel,
            ],
        ]);
    }

    public function branchVolumesAnalytics(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        $volumes = $this->analytics->branchVisitVolumes($from, $to);

        return response()->json([
            'success' => true,
            'data' => $volumes,
        ]);
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private function dateRange(Request $request): array
    {
        $from = $request->input('from', now()->subDays(29)->toDateString());
        $to = $request->input('to', now()->toDateString());

        $this->validateDates([
            'from' => $from,
            'to' => $to,
        ]);

        return [
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        ];
    }

    /**
     * @param  array{from: string, to: string}  $dates
     */
    private function validateDates(array $dates): void
    {
        validator($dates, [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ])->validate();

        if ($dates['to'] < $dates['from']) {
            abort(422, 'The "to" date must not be before the "from" date.');
        }
    }

    public function financial(): JsonResponse
    {
        $monthStart = now()->startOfMonth();
        $todayStart = now()->startOfDay();

        $revenueToday = (float) Payment::where('paid_at', '>=', $todayStart)->sum('amount');
        $revenueMonth = (float) Payment::where('paid_at', '>=', $monthStart)->sum('amount');
        $outstanding = (float) Invoice::sum('balance_due');
        $invoicesMonth = Invoice::where('issued_at', '>=', $monthStart)->count();
        $invoicedToday = (float) Invoice::where('issued_at', '>=', $todayStart)->sum('total');

        return response()->json([
            'success' => true,
            'data' => [
                ['label' => 'Revenue Today', 'total' => $revenueToday, 'period' => 'daily'],
                ['label' => 'Revenue This Month', 'total' => $revenueMonth, 'target' => 25000, 'period' => 'monthly'],
                ['label' => 'Outstanding Balance', 'total' => $outstanding, 'period' => 'monthly'],
                ['label' => 'Invoices Issued (MTD)', 'total' => $invoicesMonth, 'period' => 'monthly'],
            ],
            'meta' => [
                'currency' => $this->currency(),
                // Invoiced (billed) is deliberately distinct from revenue
                // (collected): a finalized invoice is raised before it is paid,
                // so the two cards must never be derived from the same figure.
                'invoiced_today' => $invoicedToday,
                'invoiced_today_date' => $todayStart->toDateString(),
            ],
        ]);
    }

    /**
     * ISO 4217 code the clinic bills in, so no client has to hardcode a symbol.
     */
    private function currency(): string
    {
        $code = (string) config('clinic.currency', 'PKR');

        return $code === '' ? 'PKR' : strtoupper($code);
    }

    public function visits(): JsonResponse
    {
        $rows = Appointment::query()
            ->where('status', '!=', 'cancelled')
            ->selectRaw('DATE(scheduled_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn ($row) => [
                'date' => $row->date,
                'count' => (int) $row->count,
            ])->values(),
        ]);
    }

    public function labTests(): JsonResponse
    {
        $rows = LabOrder::query()
            ->selectRaw('test_name as name, COUNT(*) as count')
            ->groupBy('test_name')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn ($row) => [
                'name' => $row->name,
                'count' => (int) $row->count,
            ])->values(),
        ]);
    }

    public function lowStock(): JsonResponse
    {
        $branchKey = 'b'.(BranchContext::id() ?? 0);

        $cached = $this->queryCache->remember(
            $branchKey,
            function (): array {
                $items = InventoryItem::query()
                    ->whereColumn('stock_quantity', '<=', 'reorder_level')
                    ->orderByRaw('stock_quantity - reorder_level')
                    ->get();

                return ['items' => $items->map(fn (InventoryItem $item): array => [
                    'item_sku' => $item->sku,
                    'item_name' => $item->item_name,
                    'current_stock' => $item->stock_quantity,
                    'reorder_level' => $item->reorder_level,
                    'shortage' => max(0, (int) $item->reorder_level - (int) $item->stock_quantity),
                ])->all()];
            },
            300,
        );

        return response()->json([
            'success' => true,
            'data' => $cached['items'],
        ]);
    }

    public function exportFinancial(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'string', 'in:daily,monthly'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $period = $validated['period'] ?? 'monthly';

        $from = isset($validated['from'])
            ? $validated['from']
            : ($period === 'daily' ? now()->toDateString() : now()->startOfMonth()->toDateString());

        $to = isset($validated['to'])
            ? $validated['to']
            : now()->toDateString();

        $invoices = Invoice::query()
            ->whereBetween('issued_at', [
                $from.' 00:00:00',
                $to.' 23:59:59',
            ])
            ->orderBy('issued_at')
            ->get();

        $columns = [
            'Period', 'Invoice Number', 'Patient', 'Status', 'Issued At',
            'Subtotal', 'Tax', 'Total', 'Paid', 'Balance Due',
        ];

        $rows = $invoices->map(function (Invoice $invoice) use ($period): array {
            return [
                $invoice->issued_at?->format($period === 'daily' ? 'Y-m-d' : 'Y-m'),
                $invoice->invoice_number,
                $invoice->patient_name ?? '',
                $invoice->status,
                $invoice->issued_at?->toDateTimeString(),
                number_format((float) $invoice->subtotal, 2),
                number_format((float) $invoice->tax, 2),
                number_format((float) $invoice->total, 2),
                number_format((float) $invoice->amount_paid, 2),
                number_format((float) $invoice->balance_due, 2),
            ];
        })->values();

        $totals = [
            'Period' => 'TOTAL',
            'Invoice Number' => '',
            'Patient' => '',
            'Status' => sprintf('%d invoice(s)', $invoices->count()),
            'Issued At' => '',
            'Subtotal' => number_format((float) $invoices->sum('subtotal'), 2),
            'Tax' => number_format((float) $invoices->sum('tax'), 2),
            'Total' => number_format((float) $invoices->sum('total'), 2),
            'Paid' => number_format((float) $invoices->sum('amount_paid'), 2),
            'Balance Due' => number_format((float) $invoices->sum('balance_due'), 2),
        ];

        $filename = sprintf('financial_export_%s_%s.csv', $period, now()->format('Y-m-d'));

        return $this->csvStream($columns, $rows, [$totals], $filename);
    }

    public function exportInventory(): StreamedResponse
    {
        $items = InventoryItem::query()->orderBy('item_name')->get();

        $columns = [
            'SKU', 'Item', 'Category', 'Unit Price', 'Stock Qty',
            'Reorder Level', 'Stock Value', 'Status', 'Shortage',
        ];

        $rows = $items->map(function (InventoryItem $item): array {
            $lowStock = $item->isLowStock();

            return [
                $item->sku,
                $item->item_name,
                $item->category,
                number_format((float) $item->unit_price, 2),
                $item->stock_quantity,
                $item->reorder_level,
                number_format((float) $item->unit_price * $item->stock_quantity, 2),
                $lowStock ? 'LOW STOCK' : 'OK',
                $lowStock ? max(0, $item->reorder_level - $item->stock_quantity) : 0,
            ];
        })->values();

        $lowStockCount = $items->filter(fn (InventoryItem $item) => $item->isLowStock())->count();

        $totals = [
            'SKU' => 'TOTAL',
            'Item' => sprintf('%d item(s), %d low', $items->count(), $lowStockCount),
            'Category' => '',
            'Unit Price' => '',
            'Stock Qty' => $items->sum('stock_quantity'),
            'Reorder Level' => '',
            'Stock Value' => number_format((float) $items->sum(fn (InventoryItem $item) => $item->stock_quantity * (float) $item->unit_price), 2),
            'Status' => '',
            'Shortage' => '',
        ];

        $filename = sprintf('inventory_audit_%s.csv', now()->format('Y-m-d'));

        return $this->csvStream($columns, $rows, [$totals], $filename);
    }

    /**
     * Build a UTF-8 BOM CSV response the browser will download as an attachment.
     *
     * @param  array<int, string>  $columns
     * @param  Collection<int, array<int, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $footer
     */
    private function csvStream(array $columns, $rows, array $footer, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows, $footer): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, $columns);

            foreach ($rows as $row) {
                fputcsv($output, $row);
            }

            foreach ($footer as $row) {
                fputcsv($output, $row);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
