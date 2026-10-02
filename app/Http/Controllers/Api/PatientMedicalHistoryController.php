<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientMedicalHistoryController extends Controller
{
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', Rule::in(PatientMedicalHistory::categories())],
        ]);

        $query = $patient->medicalHistories()
            ->with(['allergen:id,name,family', 'recorder:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($category = $validated['category'] ?? null) {
            $query->where('category', $category);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn (PatientMedicalHistory $history) => $this->present($history))->values(),
        ]);
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'string', Rule::in(PatientMedicalHistory::categories())],
            'allergen_id' => ['nullable', 'integer', 'exists:allergens,id'],
            'term' => ['nullable', 'string', 'max:255'],
            'reaction' => ['nullable', 'string', 'max:150'],
            'severity' => ['nullable', 'string', Rule::in(PatientMedicalHistory::severities())],
            'onset_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $history = $patient->medicalHistories()->create([
            'category' => $validated['category'],
            'allergen_id' => $validated['allergen_id'] ?? null,
            'term' => $validated['term'] ?? null,
            'reaction' => $validated['reaction'] ?? null,
            'severity' => $validated['severity'] ?? PatientMedicalHistory::SEVERITY_UNKNOWN,
            'onset_date' => $validated['onset_date'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'recorded_by' => $user->id,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->present($history->load(['allergen:id,name,family', 'recorder:id,name'])),
        ], 201);
    }

    public function update(Request $request, PatientMedicalHistory $medicalHistory): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', Rule::in(PatientMedicalHistory::categories())],
            'allergen_id' => ['nullable', 'integer', 'exists:allergens,id'],
            'term' => ['nullable', 'string', 'max:255'],
            'reaction' => ['nullable', 'string', 'max:150'],
            'severity' => ['nullable', 'string', Rule::in(PatientMedicalHistory::severities())],
            'onset_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $medicalHistory->fill($validated);
        $medicalHistory->save();

        return response()->json([
            'success' => true,
            'data' => $this->present($medicalHistory->load(['allergen:id,name,family', 'recorder:id,name'])),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PatientMedicalHistory $history): array
    {
        return [
            'id' => $history->id,
            'patient_id' => $history->patient_id,
            'category' => $history->category,
            'allergen_id' => $history->allergen_id,
            'allergen_name' => $history->allergen?->name,
            'allergen_family' => $history->allergen?->family,
            'term' => $history->term,
            'reaction' => $history->reaction,
            'severity' => $history->severity,
            'onset_date' => $history->onset_date?->toDateString(),
            'is_active' => $history->is_active,
            'recorded_by' => $history->recorded_by,
            'recorded_by_name' => $history->recorder?->name,
            'notes' => $history->notes,
            'created_at' => $history->created_at?->toIso8601String(),
        ];
    }
}
