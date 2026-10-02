<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicationReconciliation;
use App\Models\Patient;
use App\Models\User;
use App\Services\MedicationReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MedRecController extends Controller
{
    public function __construct(private readonly MedicationReconciliationService $medRec) {}

    public function index(Request $request, Patient $patient): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['nullable', 'string', Rule::in(MedicationReconciliation::sources())],
        ]);

        $meds = $patient->medicationReconciliations()
            ->with(['encounter:id,status', 'reconciler:id,name'])
            ->where('is_active', true)
            ->when(
                isset($validated['source']),
                fn ($query) => $query->where('source', $validated['source']),
            )
            ->orderByDesc('reconciled_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $meds->map(fn (MedicationReconciliation $med) => $this->present($med))->values(),
        ]);
    }

    /**
     * Fields whose "not supplied" value is null rather than an empty string.
     *
     * Kept in one place so `store`, `update` and `reconcile` cannot drift into
     * disagreeing about what a blank dose means.
     */
    private const OPTIONAL_TEXT_FIELDS = ['dosage', 'frequency', 'notes'];

    /**
     * Normalise a blank-but-present text field to null.
     *
     * An empty or whitespace-only dose is not a dose. Storing it would leave a
     * row that reads back as "a dose is recorded" while displaying as nothing,
     * and the CDSS dosage check would then treat `''` as a real (zero) dose.
     * Nulling it keeps "unknown" unambiguous. Only trims and nulls -- it never
     * invents or defaults a value.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normaliseOptionalText(array $attributes): array
    {
        foreach (self::OPTIONAL_TEXT_FIELDS as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $value = $attributes[$field];

            if (is_string($value) && trim($value) === '') {
                $attributes[$field] = null;
            }
        }

        return $attributes;
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $validated = $this->normaliseOptionalText($request->validate([
            'encounter_id' => ['nullable', 'integer', 'exists:encounters,id'],
            'medicine_name' => ['required', 'string', 'max:150'],
            // Nullable, matching the schema. Reconciliation records what the
            // patient reports taking, and patients routinely cannot state a
            // dose or frequency. Requiring them would not improve the record --
            // it would push clinicians to type a dose they do not know, which is
            // fabricated clinical data. An unknown dose stays explicitly null
            // and the CDSS dosage check declines to fire on it, while the
            // allergy and drug-interaction checks still run off medicine_name.
            'dosage' => ['nullable', 'string', 'max:100'],
            'frequency' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'in:self_reported,prescription_snapshot,clinician,admission,discharge'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]));

        /** @var User $user */
        $user = $request->user();

        $medRec = $this->medRec->create([
            'patient_id' => $patient->id,
            'encounter_id' => $validated['encounter_id'] ?? null,
            'medicine_name' => $validated['medicine_name'],
            'dosage' => $validated['dosage'] ?? null,
            'frequency' => $validated['frequency'] ?? null,
            'source' => $validated['source'] ?? MedicationReconciliation::SOURCE_CLINICIAN,
            'is_active' => $validated['is_active'] ?? true,
            'notes' => $validated['notes'] ?? null,
        ], $user);

        return response()->json([
            'success' => true,
            'data' => $this->present($medRec),
        ], 201);
    }

    public function update(Request $request, MedicationReconciliation $medicationReconciliation): JsonResponse
    {
        $validated = $this->normaliseOptionalText($request->validate([
            'medicine_name' => ['sometimes', 'string', 'max:150'],
            // Nullable for the same reason as `store()`, and because clearing a
            // known dose is a legitimate correction: a clinician reconciling a
            // patient may need to record "dose unknown" rather than leave a
            // stale figure that no longer matches what the patient takes.
            'dosage' => ['sometimes', 'nullable', 'string', 'max:100'],
            'frequency' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'version' => ['sometimes', 'integer', 'min:1'],
        ]));

        /** @var User $user */
        $user = $request->user();

        $medRec = $this->medRec->update($medicationReconciliation, $user, $validated);

        return response()->json([
            'success' => true,
            'data' => $this->present($medRec),
        ]);
    }

    public function deactivate(Request $request, MedicationReconciliation $medicationReconciliation): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['sometimes', 'integer', 'min:1'],
        ]);

        $medRec = $this->medRec->deactivate($medicationReconciliation, request()->user(), $validated);

        return response()->json([
            'success' => true,
            'message' => 'Medication deactivated.',
            'data' => $this->present($medRec),
        ]);
    }

    /**
     * Bulk reconcile medications for an encounter.
     */
    public function reconcile(Request $request, int $encounterId): JsonResponse
    {
        $validated = $this->normaliseOptionalText($request->validate([
            'medications' => ['required', 'array', 'min:1', 'max:50'],
            'medications.*.medicine_name' => ['required', 'string', 'max:150'],
            'medications.*.dosage' => ['nullable', 'string', 'max:100'],
            'medications.*.frequency' => ['nullable', 'string', 'max:100'],
            'medications.*.source' => ['nullable', 'string', 'in:self_reported,prescription_snapshot,clinician,admission,discharge'],
            'medications.*.is_active' => ['boolean'],
            'medications.*.notes' => ['nullable', 'string', 'max:500'],
        ]));

        $medications = array_map(
            fn (array $medication): array => $this->normaliseOptionalText($medication),
            $validated['medications'],
        );

        /** @var User $user */
        $user = $request->user();

        $result = $this->medRec->reconcileForEncounter($encounterId, $user, $medications);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MedicationReconciliation $med): array
    {
        return [
            'id' => $med->id,
            'patient_id' => $med->patient_id,
            'encounter_id' => $med->encounter_id,
            'medicine_name' => $med->medicine_name,
            'dosage' => $med->dosage,
            'frequency' => $med->frequency,
            'source' => $med->source,
            'is_active' => $med->is_active,
            // The client sends this back on update as a precondition, so a
            // stale edit is refused with 409 instead of overwriting a
            // colleague's later change.
            'version' => (int) $med->version,
            'updated_at' => $med->updated_at?->toIso8601String(),
            'reconciled_at' => $med->reconciled_at?->toIso8601String(),
            'reconciled_by' => $med->reconciled_by,
            'reconciled_by_name' => $med->reconciler?->name,
            'notes' => $med->notes,
            'created_at' => $med->created_at?->toIso8601String(),
        ];
    }
}
