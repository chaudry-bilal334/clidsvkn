<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicationReconciliation;
use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use App\Services\PatientService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    public function __construct(private readonly PatientService $patients) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Patient::query()->orderByDesc('created_at');

        if ($search = $validated['search'] ?? null) {
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $patients = $query->paginate($validated['per_page'] ?? 10);

        return response()->json([
            'success' => true,
            'data' => $patients->items(),
            'meta' => [
                'page' => $patients->currentPage(),
                'per_page' => $patients->perPage(),
                'total' => $patients->total(),
                'last_page' => $patients->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // `patients.cnic_hash` is uniquely indexed, so a CNIC that already
        // belongs to someone can never be inserted. Reject it before the
        // generic validator does, and attach the conflicting records so the
        // reception desk gets the same "you may be creating a duplicate"
        // intelligence the soft-match check would have produced. Without this
        // the receptionist gets a bare 422 and has to go re-search by hand.
        $conflicts = $this->cnicConflicts($request->input('cnic'));

        if ($conflicts !== []) {
            return response()->json([
                'success' => false,
                'message' => 'A patient with this CNIC already exists.',
                'data' => null,
                // Keep the field-level validation shape clients already parse,
                // and add the conflicting record so the desk can act on it
                // instead of re-searching by hand.
                'errors' => ['cnic' => ['A patient with this CNIC already exists.']],
                'possible_duplicates' => $this->presentDuplicates($conflicts),
            ], 422);
        }

        $data = $this->validated($request);

        $duplicates = $this->patients->findPossibleDuplicates($data);

        $patient = $this->patients->createPatient($data);

        $payload = ['success' => true, 'data' => $patient];

        if ($duplicates !== []) {
            $payload['possible_duplicates'] = $this->presentDuplicates($duplicates);
        }

        return response()->json($payload, 201);
    }

    public function show(Patient $patient): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $patient]);
    }

    public function safetyBanner(Patient $patient): JsonResponse
    {
        // Allergies from dedicated patient_allergies table (new) - primary source
        $dedicatedAllergies = $patient->patientAllergies()
            ->where('is_active', true)
            ->with(['allergen'])
            ->get()
            ->filter(fn ($h) => $h->allergen)
            ->map(fn ($h) => [
                'id' => $h->allergen->id,
                'name' => $h->allergen->name,
                'family' => $h->allergen->family,
                'reaction' => $h->reaction ?? $h->allergen->family,
                'severity' => $h->severity,
            ]);

        // Allergies from legacy medical histories (backward compatibility)
        $legacyAllergies = $patient->medicalHistories()
            ->where('category', PatientMedicalHistory::CATEGORY_ALLERGY)
            ->where('is_active', true)
            ->with(['allergen'])
            ->get()
            ->filter(fn ($h) => $h->allergen)
            ->map(fn ($h) => [
                'id' => $h->allergen->id,
                'name' => $h->allergen->name,
                'family' => $h->allergen->family,
                'reaction' => $h->reaction,
                'severity' => $h->severity,
            ]);

        // Use dedicated allergies as primary, fall back to legacy if no dedicated allergies
        $allergies = $dedicatedAllergies->isNotEmpty() ? $dedicatedAllergies : $legacyAllergies;

        // Current medications from medication reconciliation
        $currentMeds = MedicationReconciliation::where('patient_id', $patient->id)
            ->where('is_active', true)
            ->orderByDesc('reconciled_at')
            ->get()
            ->map(fn ($m) => [
                'medicine_name' => $m->medicine_name,
                'dosage' => $m->dosage,
                'frequency' => $m->frequency,
                'source' => $m->source,
                'notes' => $m->notes,
            ]);

        // Critical history from legacy medical histories
        $criticalHistory = $patient->medicalHistories()
            ->where('category', PatientMedicalHistory::CATEGORY_CONDITION)
            ->where('is_active', true)
            ->get()
            ->map(fn ($h) => [
                'term' => $h->term,
                'severity' => $h->severity,
                'notes' => $h->notes,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'allergies' => $allergies->values(),
                'current_medications' => $currentMeds->values(),
                'critical_history' => $criticalHistory->values(),
            ],
        ]);
    }

    public function update(Request $request, Patient $patient): JsonResponse
    {
        $patient->update($this->validated($request));

        return response()->json(['success' => true, 'data' => $patient]);
    }

    public function destroy(Patient $patient): JsonResponse
    {
        // Indefinite-retention decision (docs/UNRESOLVED_DECISIONS.md): a
        // patient with any clinical history must never be deletable. Blocked
        // outright — not soft-deleted — so the record stays visible forever.
        // Zero-history patients (mistaken/duplicate registrations) continue to
        // hard-delete, matching the duplicate-merge path's convention.
        if ($this->patients->hasClinicalHistory($patient)) {
            abort(422, 'Cannot delete patient with existing clinical records.');
        }

        $patient->delete();

        return response()->json(['success' => true, 'message' => 'Patient deleted.']);
    }

    /**
     * Merge two patient records, reassigning every child record from the
     * duplicate to the survivor.
     *
     * The duplicate patient row is deleted at the end of the transaction.
     * Both patients must belong to the same branch. If both have linked
     * user accounts the merge is refused (422).
     */
    public function merge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'survivor_id' => ['required', 'integer', 'exists:patients,id'],
            'duplicate_id' => ['required', 'integer', 'exists:patients,id', 'different:survivor_id'],
            'reason' => ['required', 'string', 'max:255'],
            'promote' => ['nullable', 'array'],
            'promote.cnic' => ['nullable', 'string', 'max:255'],
            'promote.email' => ['nullable', 'email', 'max:150'],
            'promote.phone' => ['nullable', 'string', 'max:40'],
            'promote.date_of_birth' => ['nullable', 'date'],
            'promote.gender' => ['nullable', 'string', 'max:20'],
            'promote.address' => ['nullable', 'string', 'max:255'],
        ]);

        $survivor = Patient::query()->findOrFail($validated['survivor_id']);
        $duplicate = Patient::query()->findOrFail($validated['duplicate_id']);

        $merged = $this->patients->mergePatients(
            $survivor,
            $duplicate,
            $request->user(),
            $validated['reason'] ?? null,
            $validated['promote'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Patient records merged. Duplicate record deleted.',
            'data' => $merged,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Existing patients that already hold the submitted CNIC.
     *
     * @return array<int, array{patient: Patient, score: int, reasons: array<int, string>}>
     */
    private function cnicConflicts(mixed $rawCnic): array
    {
        $normalized = PatientService::normalizeCnic(is_string($rawCnic) ? $rawCnic : null);

        if ($normalized === null) {
            return [];
        }

        $conflicts = Patient::query()
            ->where('cnic_hash', PatientService::cnicHash($normalized))
            ->get();

        return $conflicts
            ->map(fn (Patient $patient): array => [
                'patient' => $patient,
                'score' => 100,
                'reasons' => ['cnic_exact_match'],
            ])
            ->all();
    }

    /**
     * Flatten duplicate-match rows into the API response shape.
     *
     * @param  array<int, array{patient: Patient, score: int, reasons: array<int, string>}>  $matches
     * @return array<int, array{id: mixed, mrn: ?string, name: ?string, score: int, reasons: array<int, string>}>
     */
    private function presentDuplicates(array $matches): array
    {
        return array_map(
            fn (array $match): array => [
                'id' => $match['patient']->getKey(),
                'mrn' => $match['patient']->mrn,
                'name' => $match['patient']->name,
                'score' => $match['score'],
                'reasons' => $match['reasons'],
            ],
            $matches,
        );
    }

    private function validated(Request $request): array
    {
        $patientId = $request->route('patient');

        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('patients', 'email')->ignore($patientId)],
            // The encrypted `cnic` column cannot be indexed, so uniqueness is
            // enforced against its deterministic blind index. Checked here so a
            // duplicate registration is a 422 with a readable message rather
            // than a raw unique-constraint 500 from the database.
            'cnic' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($patientId): void {
                    $normalized = PatientService::normalizeCnic(is_string($value) ? $value : null);

                    if ($normalized === null) {
                        return;
                    }

                    $exists = Patient::query()
                        ->where('cnic_hash', PatientService::cnicHash($normalized))
                        ->when($patientId !== null, fn ($query) => $query->whereKeyNot($patientId))
                        ->exists();

                    if ($exists) {
                        $fail('A patient with this CNIC already exists.');
                    }
                },
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
