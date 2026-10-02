<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClinicalDecisionSupport;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicalDecisionSupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CdssController extends Controller
{
    public function __construct(private readonly ClinicalDecisionSupportService $cds) {}

    public function alerts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ClinicalDecisionSupport::class);

        $validated = $request->validate([
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'acknowledged' => ['nullable', 'boolean'],
        ]);

        $filters = array_filter([
            'patient_id' => isset($validated['patient_id']) ? (int) $validated['patient_id'] : null,
            'acknowledged' => isset($validated['acknowledged']) ? (bool) $validated['acknowledged'] : null,
        ], fn ($value) => $value !== null);

        /** @var User $user */
        $user = $request->user();
        if ($user->role !== 'Admin') {
            $filters['doctor_id'] = $user->id;
        }

        return response()->json([
            'success' => true,
            'data' => $this->cds->activeAlerts($filters)->map(fn (ClinicalDecisionSupport $alert) => $this->present($alert))->values(),
        ]);
    }

    public function evaluate(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('evaluate', ClinicalDecisionSupport::class);

        $validated = $request->validate([
            'medicines' => ['nullable', 'array', 'max:50'],
            'medicines.*' => ['string', 'max:150'],
        ]);

        /** @var User $doctor */
        $doctor = $request->user();

        $alerts = $this->cds->evaluateForPatient($patient, $doctor, $validated['medicines'] ?? null);

        return response()->json([
            'success' => true,
            'data' => $alerts->map(fn (ClinicalDecisionSupport $alert) => $this->present($alert))->values(),
        ], 201);
    }

    public function acknowledge(Request $request, ClinicalDecisionSupport $alert): JsonResponse
    {
        $this->authorize('acknowledge', $alert);

        $alert->update(['is_acknowledged' => true]);

        return response()->json([
            'success' => true,
            'data' => $this->present($alert->load(['patient:id,name', 'doctor:id,name'])),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ClinicalDecisionSupport $alert): array
    {
        return [
            'id' => $alert->id,
            'patient_id' => $alert->patient_id,
            'patient_name' => $alert->patient?->name ?? 'Unknown Patient',
            'doctor_id' => $alert->doctor_id,
            'doctor_name' => $alert->doctor?->name ?? null,
            'trigger_type' => $alert->trigger_type,
            'recommendation' => $alert->recommendation,
            'is_acknowledged' => $alert->is_acknowledged,
            'created_at' => $alert->created_at?->toIso8601String(),
        ];
    }
}
