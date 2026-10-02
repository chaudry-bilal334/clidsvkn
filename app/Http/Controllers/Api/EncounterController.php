<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\User;
use App\Services\EncounterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EncounterController extends Controller
{
    public function __construct(private readonly EncounterService $encounters) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['nullable', 'integer'],
            'doctor_id' => ['nullable', 'integer'],
        ]);

        $query = Encounter::query()
            ->with(['patient:id,name,mrn', 'doctor:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($patientId = $validated['patient_id'] ?? null) {
            $query->where('patient_id', $patientId);
        }

        if ($doctorId = $validated['doctor_id'] ?? null) {
            $query->where('doctor_id', $doctorId);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn (Encounter $encounter) => $this->brief($encounter))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'notes' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $encounter = $this->encounters->openEncounter(
            (int) $validated['patient_id'],
            $user,
            isset($validated['appointment_id']) ? (int) $validated['appointment_id'] : null,
            $validated['notes'] ?? null,
        );

        return response()->json([
            'success' => true,
            'data' => $this->full($encounter->load('patient:id,name,mrn', 'doctor:id,name', 'vitals', 'diagnoses')),
        ], 201);
    }

    public function show(Encounter $encounter): JsonResponse
    {
        $encounter->load('patient:id,name,mrn', 'doctor:id,name', 'vitals', 'diagnoses');

        return response()->json([
            'success' => true,
            'data' => $this->full($encounter),
        ]);
    }

    public function finalize(Encounter $encounter): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        $finalized = $this->encounters->finalize($encounter, $user);

        return response()->json([
            'success' => true,
            'data' => $this->full($finalized->load('patient:id,name,mrn', 'doctor:id,name', 'vitals', 'diagnoses')),
        ]);
    }

    public function void(Request $request, Encounter $encounter): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $voided = $this->encounters->void($encounter, $user, $validated['reason']);

        return response()->json([
            'success' => true,
            'data' => $this->full($voided->load('patient:id,name,mrn', 'doctor:id,name', 'vitals', 'diagnoses')),
        ]);
    }

    public function amend(Encounter $encounter): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        $amendment = $this->encounters->amend($encounter, $user);

        return response()->json([
            'success' => true,
            'data' => $this->full($amendment->load('patient:id,name,mrn', 'doctor:id,name', 'vitals', 'diagnoses')),
        ], 201);
    }

    public function recordVitals(Request $request, Encounter $encounter): JsonResponse
    {
        $validated = $request->validate([
            'blood_pressure' => ['nullable', 'string', 'max:20'],
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'heart_rate' => ['nullable', 'integer', 'between:20,300'],
            'weight' => ['nullable', 'numeric', 'between:1,400'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $vital = $this->encounters->recordVitals($encounter, $user, $validated);

        return response()->json([
            'success' => true,
            'data' => $vital,
        ], 201);
    }

    public function recordDiagnosis(Request $request, Encounter $encounter): JsonResponse
    {
        $validated = $request->validate([
            'icd_code' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $diagnosis = $this->encounters->recordDiagnosis(
            $encounter,
            $user,
            $validated['icd_code'],
            $validated['description'] ?? null,
        );

        return response()->json([
            'success' => true,
            'data' => $diagnosis,
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function brief(Encounter $encounter): array
    {
        return [
            'id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'patient_name' => $encounter->patient?->name,
            'mrn' => $encounter->patient?->mrn,
            'doctor_id' => $encounter->doctor_id,
            'doctor_name' => $encounter->doctor?->name,
            'appointment_id' => $encounter->appointment_id,
            'status' => $encounter->status,
            'finalized_at' => $encounter->finalized_at?->toIso8601String(),
            'finalized_by' => $encounter->finalized_by,
            'voided_at' => $encounter->voided_at?->toIso8601String(),
            'void_reason' => $encounter->void_reason,
            'amends_encounter_id' => $encounter->amends_encounter_id,
            'created_at' => $encounter->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function full(Encounter $encounter): array
    {
        return [
            ...$this->brief($encounter),
            'notes' => $encounter->notes,
            'vitals' => $encounter->vitals->map(fn ($vital) => [
                'id' => $vital->id,
                'blood_pressure' => $vital->blood_pressure,
                'temperature' => $vital->temperature,
                'heart_rate' => $vital->heart_rate,
                'weight' => $vital->weight,
                'recorded_at' => $vital->created_at?->toIso8601String(),
            ])->values(),
            'diagnoses' => $encounter->diagnoses->map(fn ($diagnosis) => [
                'id' => $diagnosis->id,
                'icd_code' => $diagnosis->icd_code,
                'description' => $diagnosis->description,
                'recorded_at' => $diagnosis->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
