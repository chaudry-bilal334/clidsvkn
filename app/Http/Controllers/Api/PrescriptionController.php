<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePrescriptionRequest;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Services\ClinicalDecisionSupportService;
use App\Services\PrescriptionService;
use App\Services\ReferenceDataReadinessService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrescriptionController extends Controller
{
    public function __construct(
        private readonly ClinicalDecisionSupportService $cdss,
        private readonly PrescriptionService $prescriptions,
        private readonly ReferenceDataReadinessService $readiness,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['nullable', 'integer'],
        ]);

        $query = Prescription::query()
            ->with('items')
            ->orderByDesc('created_at');

        if ($patientId = $validated['patient_id'] ?? null) {
            $query->where('patient_id', $patientId);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn (Prescription $prescription) => $this->present($prescription))->values(),
        ]);
    }

    public function store(StorePrescriptionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Patient $patient */
        $patient = Patient::findOrFail($request->integer('patient_id'));

        // The ruleset itself is the precondition. Without approved allergy,
        // dosage and interaction data the safety engine reports "no problems"
        // for every patient, so refuse rather than issue an unchecked
        // prescription. Enforced in production by default (see
        // ReferenceDataReadinessService::enforcementEnabled()).
        if ($reason = $this->readiness->blockingReason()) {
            return response()->json([
                'success' => false,
                'message' => $reason,
                'errors' => [
                    'reference_data' => [
                        'ready' => false,
                        'missing' => $this->readiness->missingTypes(),
                    ],
                ],
            ], 503);
        }

        $warnings = $this->cdss->checkCandidateMedicines($patient, $request->validated('items'));

        $overrideReason = $request->input('override_reason');

        if ($warnings !== [] && blank($overrideReason)) {
            return response()->json([
                'success' => false,
                'message' => 'Prescription blocked by clinical safety checks. Provide an override_reason to proceed.',
                'errors' => [
                    'safety_alerts' => $warnings,
                ],
            ], 422);
        }

        $prescription = $this->prescriptions->create([
            'patient_id' => $patient->id,
            'patient_name' => $patient->name,
            'doctor_id' => $user->id,
            'doctor_name' => $user->name,
            'appointment_id' => $request->integer('appointment_id'),
            'notes' => $request->input('notes'),
            'items' => $request->lineItems(),
        ], $user);

        if ($warnings !== [] && is_string($overrideReason) && $overrideReason !== '') {
            AuditLog::record(
                $user,
                'prescription.override',
                $prescription,
                newValues: [
                    'override_reason' => $overrideReason,
                    'safety_alerts' => $warnings,
                ],
                ipAddress: $request->ip(),
            );
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($prescription->load('items')),
        ], 201);
    }

    public function finalize(Request $request, Prescription $prescription): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $finalized = $this->prescriptions->finalize($prescription, $user);

        return response()->json([
            'success' => true,
            'data' => $this->present($finalized->load('items')),
        ]);
    }

    public function void(Request $request, Prescription $prescription): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $voided = $this->prescriptions->void($prescription, $user, $validated['reason']);

        return response()->json([
            'success' => true,
            'data' => $this->present($voided->load('items')),
        ]);
    }

    public function amend(Request $request, Prescription $prescription): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $amendment = $this->prescriptions->amend($prescription, $user);

        return response()->json([
            'success' => true,
            'data' => $this->present($amendment->load('items')),
        ], 201);
    }

    public function pdf(Prescription $prescription)
    {
        $prescription->load('items');

        $pdf = Pdf::loadView('pdf.prescription', [
            'prescription' => $prescription,
            'clinic' => config('clinic'),
        ])->setPaper('a4');

        return $pdf->download("prescription-{$prescription->prescription_number}.pdf");
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Prescription $prescription): array
    {
        return [
            'id' => $prescription->id,
            'prescription_number' => $prescription->prescription_number,
            'patient_id' => $prescription->patient_id,
            'patient_name' => $prescription->patient_name,
            'doctor_name' => $prescription->doctor_name,
            'appointment_id' => $prescription->appointment_id,
            'status' => $prescription->status,
            'finalized_at' => $prescription->finalized_at?->toIso8601String(),
            'finalized_by' => $prescription->finalized_by,
            'voided_at' => $prescription->voided_at?->toIso8601String(),
            'void_reason' => $prescription->void_reason,
            'amends_prescription_id' => $prescription->amends_prescription_id,
            'created_at' => $prescription->created_at?->toIso8601String(),
            'items' => $prescription->items->map(fn ($item) => [
                'medicine_name' => $item->medicine_name,
                'dosage' => $item->dosage,
                'frequency' => $item->frequency,
                'duration' => $item->duration,
                'instructions' => $item->instructions,
                'quantity' => $item->quantity,
            ])->values(),
        ];
    }
}
