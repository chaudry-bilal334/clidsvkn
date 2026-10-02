<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\BillingService;
use App\Services\RefundService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly BillingService $billing,
    ) {}

    public function index(): JsonResponse
    {
        $invoices = Invoice::query()
            ->with('payments')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $invoices->map(fn (Invoice $invoice) => $this->present($invoice))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_name' => ['required', 'string', 'max:150'],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'tax' => ['required', 'numeric', 'min:0'],
        ]);

        // `total`, `amount_paid`, `balance_due` and `status` are all derived on
        // the server. A client that could post its own `balance_due` would be
        // posting `amount_paid` too, and a client that could post its own
        // `status` could mark a charge collected without a payment row.
        $subtotal = round((float) $validated['subtotal'], 2);
        $tax = round((float) $validated['tax'], 2);
        $total = round($subtotal + $tax, 2);

        $invoice = Invoice::create([
            'invoice_number' => 'TMP-'.Str::uuid(),
            'patient_id' => $validated['patient_id'] ?? null,
            'patient_name' => $validated['patient_name'],
            'status' => Invoice::STATUS_GENERATED,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'amount_paid' => 0,
            'balance_due' => $total,
            'issued_at' => now(),
        ]);

        $invoice->invoice_number = sprintf('INV-%05d', $invoice->id);
        $invoice->save();

        $invoice->items()->create([
            'description' => 'Consultation',
            'quantity' => 1,
            'unit_price' => $subtotal,
            'line_total' => $subtotal,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->present($invoice->load('payments')),
        ], 201);
    }

    public function recordPayment(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', Payment::METHODS)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->billing->recordPayment(
            $invoice,
            (float) $validated['amount'],
            $validated['method'] ?? Payment::METHOD_CASH,
            $user,
            $validated['reference'] ?? null,
        );

        $invoice->refresh()->load('payments');

        return response()->json([
            'success' => true,
            'data' => $this->present($invoice),
        ]);
    }

    public function pdf(Invoice $invoice)
    {
        $invoice->load(['items', 'payments']);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'clinic' => config('clinic'),
        ])->setPaper('a4');

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }

    public function finalize(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'confirmation' => ['required', 'boolean', 'accepted'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->billing->finalizeInvoice($invoice, $user);

        $invoice->refresh()->load('payments');

        return response()->json([
            'success' => true,
            'data' => $this->present($invoice),
        ]);
    }

    public function reversePayment(Request $request, Invoice $invoice, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $reversal = $this->billing->reversePayment($payment, $user, $validated['reason']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $reversal->id,
                'invoice_id' => $reversal->invoice_id,
                'reversal_of' => $reversal->reversal_of,
                'amount' => (float) $reversal->amount,
                'method' => $reversal->method,
                'reference' => $reversal->reference,
                'status' => $reversal->status,
                'received_by' => $reversal->receivedBy?->name,
                'created_at' => $reversal->created_at?->toIso8601String(),
                'invoice' => $this->present($reversal->invoice),
            ],
        ], 201);
    }

    public function refund(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_id' => ['nullable', 'integer', 'exists:payments,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $payment = isset($validated['payment_id']) ? Payment::find($validated['payment_id']) : null;

        $refund = $this->refunds->processRefund(
            $invoice,
            $user,
            (float) $validated['amount'],
            $validated['reason'],
            $payment,
        );

        $refund->load(['invoice.payments', 'payment', 'processedBy']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $refund->id,
                'invoice_id' => $refund->invoice_id,
                'payment_id' => $refund->payment_id,
                'amount' => (float) $refund->amount,
                'reason' => $refund->reason,
                'processed_by' => $refund->processedBy?->name,
                'created_at' => $refund->created_at?->toIso8601String(),
                'invoice' => $this->present($refund->invoice),
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Invoice $invoice): array
    {
        $lastPayment = $invoice->payments->sortByDesc('paid_at')->first();

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'patient_id' => $invoice->patient_id,
            'patient_name' => $invoice->patient_name,
            'subtotal' => (float) $invoice->subtotal,
            'tax' => (float) $invoice->tax,
            'total' => (float) $invoice->total,
            'amount_paid' => (float) $invoice->amount_paid,
            'balance_due' => (float) $invoice->balance_due,
            'status' => $invoice->status,
            'is_finalized' => $invoice->isFinalized(),
            'finalized_at' => $invoice->finalized_at?->toIso8601String(),
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'void_reason' => $invoice->void_reason,
            'created_at' => $invoice->created_at?->toIso8601String(),
            'payment_date' => $lastPayment?->paid_at?->toIso8601String(),
        ];
    }
}
