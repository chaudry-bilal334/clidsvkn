<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispensing;
use App\Models\Invoice;
use App\Models\Prescription;
use App\Models\User;
use App\Services\DispensingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DispensingController extends Controller
{
    public function __construct(private readonly DispensingService $dispensing) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prescription_id' => ['required', 'integer', 'exists:prescriptions,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.medicine_name' => ['required', 'string', 'max:150'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $prescription = Prescription::findOrFail($validated['prescription_id']);

        /** @var User $user */
        $user = $request->user();

        $dispensings = $this->dispensing->dispense($prescription, $validated['items'], $user);

        $group = $dispensings->first()?->dispenseGroup;

        return response()->json([
            'success' => true,
            'data' => [
                'prescription_id' => $prescription->id,
                'prescription_number' => $prescription->prescription_number,
                'status' => $prescription->fresh()->status,
                'dispense_group_id' => $group?->id,
                'dispensings' => $dispensings->map(fn (Dispensing $dispensing) => [
                    'id' => $dispensing->id,
                    'inventory_batch_id' => $dispensing->inventory_batch_id,
                    'quantity_dispensed' => $dispensing->quantity_dispensed,
                    'dispensed_by' => $dispensing->dispensed_by,
                ])->values(),
                'invoice' => $group?->invoice ? $this->presentInvoice($group->invoice) : null,
            ],
        ], 201);
    }

    public function void(Request $request, Dispensing $dispensing): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $voided = $this->dispensing->void($dispensing, $user, $validated['reason']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $voided->id,
                'stock_returned' => true,
                'invoice_voided' => $voided->invoice_id === null,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentInvoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'total' => (float) $invoice->total,
            'status' => $invoice->status,
        ];
    }
}
