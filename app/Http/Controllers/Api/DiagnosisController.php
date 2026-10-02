<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\User;
use App\Services\DiagnosisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiagnosisController extends Controller
{
    public function __construct(private readonly DiagnosisService $diagnoses) {}

    public function index(Request $request, Encounter $encounter): JsonResponse
    {
        $diagnoses = $encounter->diagnoses()->with('recordedBy:id,name')->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $diagnoses->map(fn (Diagnosis $diagnosis) => $this->present($diagnosis))->values(),
        ]);
    }

    public function store(Request $request, Encounter $encounter): JsonResponse
    {
        $validated = $request->validate([
            'icd_code' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $diagnosis = $this->diagnoses->record($encounter, $user, $validated['icd_code'], $validated['description'] ?? null);

        return response()->json([
            'success' => true,
            'data' => $this->present($diagnosis),
        ], 201);
    }

    public function update(Request $request, Diagnosis $diagnosis): JsonResponse
    {
        $validated = $request->validate([
            'icd_code' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $diagnosis = $this->diagnoses->update($diagnosis, request()->user(), $validated['icd_code'], $validated['description'] ?? null);

        return response()->json([
            'success' => true,
            'data' => $this->present($diagnosis),
        ]);
    }

    public function destroy(Diagnosis $diagnosis): JsonResponse
    {
        $this->diagnoses->delete($diagnosis, request()->user());

        return response()->json(['success' => true, 'message' => 'Diagnosis deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Diagnosis $diagnosis): array
    {
        return [
            'id' => $diagnosis->id,
            'encounter_id' => $diagnosis->encounter_id,
            'icd_code' => $diagnosis->icd_code,
            'description' => $diagnosis->description,
            'recorded_by' => $diagnosis->recorded_by,
            'recorded_by_name' => $diagnosis->recordedBy?->name,
            'recorded_at' => $diagnosis->created_at?->toIso8601String(),
        ];
    }
}
