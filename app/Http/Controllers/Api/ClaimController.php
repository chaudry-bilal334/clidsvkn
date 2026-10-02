<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClaimRequest;
use App\Http\Requests\UpdateClaimRequest;
use App\Models\Claim;
use App\Services\ClaimService;
use App\Services\InsuranceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    public function __construct(
        private readonly InsuranceService $insurance,
        private readonly ClaimService $claims,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Claim::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', $this->insurance->statuses())],
            'patient_id' => ['nullable', 'integer'],
            'insurance_provider_id' => ['nullable', 'integer'],
        ]);

        $query = Claim::query()
            ->with(['patient:id,name', 'invoice:id,invoice_number,total,balance_due', 'insuranceProvider:id,name,code'])
            ->orderByDesc('created_at');

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($patientId = $validated['patient_id'] ?? null) {
            $query->where('patient_id', $patientId);
        }

        if ($providerId = $validated['insurance_provider_id'] ?? null) {
            $query->where('insurance_provider_id', $providerId);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn (Claim $claim) => $this->present($claim))->values(),
        ]);
    }

    public function store(StoreClaimRequest $request): JsonResponse
    {
        $this->authorize('create', Claim::class);

        $claim = $this->claims->create([
            'patient_id' => $request->integer('patient_id'),
            'invoice_id' => $request->integer('invoice_id'),
            'insurance_provider_id' => $request->integer('insurance_provider_id'),
            'policy_number' => $request->input('policy_number'),
            'claimed_amount' => $request->input('claimed_amount'),
            'notes' => $request->input('notes'),
        ], $request->user());

        return response()->json([
            'success' => true,
            'data' => $this->present($claim->load(['patient:id,name', 'invoice:id,invoice_number,total,balance_due', 'insuranceProvider:id,name,code'])),
        ], 201);
    }

    public function show(Claim $claim): JsonResponse
    {
        $this->authorize('view', $claim);

        return response()->json([
            'success' => true,
            'data' => $this->present($claim->load(['patient:id,name', 'invoice:id,invoice_number,total,balance_due', 'insuranceProvider:id,name,code'])),
        ]);
    }

    public function update(UpdateClaimRequest $request, Claim $claim): JsonResponse
    {
        $this->authorize('update', $claim);

        if ($claim->status !== Claim::STATUS_DRAFT) {
            abort(422, 'Only draft claims can be edited.');
        }

        $claim->fill($request->validated());
        $claim->save();

        return response()->json([
            'success' => true,
            'data' => $this->present($claim->load(['patient:id,name', 'invoice:id,invoice_number,total,balance_due', 'insuranceProvider:id,name,code'])),
        ]);
    }

    public function destroy(Claim $claim): JsonResponse
    {
        $this->authorize('delete', $claim);

        $claim->delete();

        return response()->json(['success' => true, 'message' => 'Claim deleted.']);
    }

    public function transitionStatus(Request $request, Claim $claim): JsonResponse
    {
        $this->authorize('transitionStatus', $claim);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', $this->insurance->statuses())],
            'approved_amount' => ['nullable', 'numeric', 'min:0.01', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->insurance->transition(
            $claim,
            $validated['status'],
            isset($validated['approved_amount']) ? (float) $validated['approved_amount'] : null,
            $validated['notes'] ?? null,
        );

        return response()->json([
            'success' => true,
            'data' => $this->present($claim->load(['patient:id,name', 'invoice:id,invoice_number,total,balance_due', 'insuranceProvider:id,name,code', 'invoice.payments'])),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Claim $claim): array
    {
        return [
            'id' => $claim->id,
            'patient_id' => $claim->patient_id,
            'patient_name' => $claim->patient?->name ?? 'Unknown Patient',
            'invoice_id' => $claim->invoice_id,
            'invoice_number' => $claim->invoice?->invoice_number,
            'invoice_total' => $claim->invoice ? (float) $claim->invoice->total : null,
            'invoice_balance_due' => $claim->invoice ? (float) $claim->invoice->balance_due : null,
            'insurance_provider_id' => $claim->insurance_provider_id,
            'provider_name' => $claim->insuranceProvider?->name,
            'provider_code' => $claim->insuranceProvider?->code,
            'policy_number' => $claim->policy_number,
            'claimed_amount' => (float) $claim->claimed_amount,
            'approved_amount' => $claim->approved_amount === null ? null : (float) $claim->approved_amount,
            'status' => $claim->status,
            'notes' => $claim->notes,
            'created_at' => $claim->created_at?->toIso8601String(),
        ];
    }
}
