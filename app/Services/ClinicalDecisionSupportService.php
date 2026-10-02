<?php

namespace App\Services;

use App\Models\Allergen;
use App\Models\ClinicalDecisionSupport;
use App\Models\DosageRule;
use App\Models\DrugInteractionRule;
use App\Models\MedicationReconciliation;
use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use App\Models\PrescriptionItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ClinicalDecisionSupportService
{
    /**
     * Evaluate the patient's active prescriptions and write any triggered
     * clinical alerts, returning the freshly created recommendations.
     *
     * @param  array<int, string>|null  $candidateMedicineNames  Optional medicines being prescribed right now
     * @return Collection<int, ClinicalDecisionSupport>
     */
    public function evaluateForPatient(Patient $patient, User $doctor, ?array $candidateMedicineNames = null): Collection
    {
        $active = $this->activeMedications($patient);

        $names = collect($candidateMedicineNames ?? [])
            ->map(fn (string $name) => $this->itemFor($name))
            ->filter()
            ->values()
            ->merge($active)
            ->reject(fn (?PrescriptionItem $item) => $item === null)
            ->values();

        $alerts = collect();

        foreach ($this->interactions($names) as $interaction) {
            $alerts->push($this->createAlert($patient, $doctor, ClinicalDecisionSupport::TRIGGER_DRUG_INTERACTION, $interaction));
        }

        foreach ($names as $item) {
            if ($dosage = $this->dosageWarning($item)) {
                $alerts->push($this->createAlert($patient, $doctor, ClinicalDecisionSupport::TRIGGER_DOSAGE_CHECK, $dosage));
            }

            if ($allergy = $this->allergyWarning($patient, $item)) {
                $alerts->push($this->createAlert($patient, $doctor, ClinicalDecisionSupport::TRIGGER_ALLERGY_WARNING, $allergy));
            }
        }

        return $alerts->filter()->values();
    }

    /**
     * Evaluate a set of candidate prescription line items without persisting
     * alerts, returning structured warnings a caller can block on.
     *
     * @param  array<int, array{medicine_name: string, dosage?: string|null, frequency?: string|null}>  $candidateItems
     * @return array<int, array{trigger_type: string, message: string}>
     */
    public function checkCandidateMedicines(Patient $patient, array $candidateItems): array
    {
        $candidate = collect($candidateItems)
            ->map(fn (array $item) => new PrescriptionItem([
                'medicine_name' => $item['medicine_name'],
                'dosage' => $item['dosage'] ?? '',
                'frequency' => $item['frequency'] ?? '',
            ]))
            ->filter()
            ->values();

        $all = $candidate->merge($this->activeMedications($patient));

        $warnings = [];

        foreach ($this->interactions($all) as $message) {
            $warnings[] = [
                'trigger_type' => ClinicalDecisionSupport::TRIGGER_DRUG_INTERACTION,
                'message' => $message,
            ];
        }

        foreach ($candidate as $item) {
            if ($dosage = $this->dosageWarning($item)) {
                $warnings[] = [
                    'trigger_type' => ClinicalDecisionSupport::TRIGGER_DOSAGE_CHECK,
                    'message' => $dosage,
                ];
            }

            if ($allergy = $this->allergyWarning($patient, $item)) {
                $warnings[] = [
                    'trigger_type' => ClinicalDecisionSupport::TRIGGER_ALLERGY_WARNING,
                    'message' => $allergy,
                ];
            }
        }

        return $warnings;
    }

    /**
     * Active (unacknowledged) clinical alerts visible to a clinician.
     *
     * @param  array{patient_id?: int, acknowledged?: bool, doctor_id?: int}  $filters
     */
    public function activeAlerts(array $filters = []): Collection
    {
        $query = ClinicalDecisionSupport::query()
            ->with(['patient:id,name', 'doctor:id,name'])
            ->latest();

        if (isset($filters['patient_id'])) {
            $query->where('patient_id', $filters['patient_id']);
        }

        if (isset($filters['doctor_id'])) {
            $query->where('doctor_id', $filters['doctor_id']);
        }

        if (array_key_exists('acknowledged', $filters)) {
            $query->where('is_acknowledged', $filters['acknowledged'] ? 1 : 0);
        } else {
            $query->where('is_acknowledged', false);
        }

        return $query->get();
    }

    /**
     * Current effective medications: active prescription items plus active
     * medication-reconciliation rows.
     *
     * @return Collection<int, PrescriptionItem>
     */
    private function activeMedications(Patient $patient): Collection
    {
        $active = $this->activePrescriptionItems($patient);

        $reconciled = $patient->medicationReconciliations()
            ->where('is_active', true)
            ->get(['medicine_name', 'dosage', 'frequency'])
            ->map(fn (MedicationReconciliation $reconciliation) => new PrescriptionItem([
                'medicine_name' => $reconciliation->medicine_name,
                'dosage' => $reconciliation->dosage ?? '',
                'frequency' => $reconciliation->frequency ?? '',
            ]));

        return $active->concat($reconciled)->values();
    }

    /**
     * @return Collection<int, PrescriptionItem>
     */
    private function activePrescriptionItems(Patient $patient): Collection
    {
        return PrescriptionItem::query()
            ->whereHas('prescription', function ($query) use ($patient): void {
                $query->where('patient_id', $patient->id)
                    ->whereIn('status', ['pending', 'dispensed']);
            })
            ->select('medicine_name', 'dosage', 'frequency')
            ->get();
    }

    private function itemFor(string $medicineName): ?PrescriptionItem
    {
        return new PrescriptionItem([
            'medicine_name' => $medicineName,
            'dosage' => '',
            'frequency' => '',
        ]);
    }

    /**
     * Detect known interactions within the supplied prescription line items,
     * matching each drug-interaction rule's canonical name and aliases.
     *
     * @param  Collection<int, PrescriptionItem>  $items
     * @return Collection<int, string>
     */
    private function interactions(Collection $items): Collection
    {
        $names = $items->pluck('medicine_name')->map(fn (string $name) => strtolower($name));

        $found = collect();

        foreach (DrugInteractionRule::query()->orderBy('medicine_a')->get() as $rule) {
            $matchesA = $names->filter(fn (string $name) => $this->matchesRule($name, $rule->medicine_a, $rule->aliases_a));
            $matchesB = $names->filter(fn (string $name) => $this->matchesRule($name, $rule->medicine_b, $rule->aliases_b));

            if ($matchesA->isNotEmpty() && $matchesB->isNotEmpty()) {
                $fallback = "Potential interaction between {$rule->medicine_a} and {$rule->medicine_b} detected.";
                $found->push($this->ruleMessage($rule->guidance, $fallback, $rule->is_approved));
            }
        }

        return $found;
    }

    private function dosageWarning(PrescriptionItem $item): ?string
    {
        $rule = $this->dosageRuleFor($item->medicine_name);

        if ($rule === null) {
            return null;
        }

        $dailyMg = $this->parseDailyMilligrams($item->dosage, $item->frequency);

        if ($dailyMg === null || $dailyMg <= $rule->max_daily_mg) {
            return null;
        }

        $maxDaily = number_format($rule->max_daily_mg, 0, '.', '');
        $fallback = "Daily dose of {$this->titleCase($item->medicine_name)} (".number_format($dailyMg)." mg) exceeds the recommended maximum of {$maxDaily} mg per day.";

        return $this->ruleMessage(null, $fallback, $rule->is_approved);
    }

    private function allergyWarning(Patient $patient, PrescriptionItem $item): ?string
    {
        $allergen = $this->allergenFor($item->medicine_name);

        if ($allergen === null) {
            return null;
        }

        $documented = $patient->medicalHistories()
            ->where('category', PatientMedicalHistory::CATEGORY_ALLERGY)
            ->where('is_active', true)
            ->where(function ($query) use ($allergen): void {
                $query->where('allergen_id', $allergen->id)
                    ->orWhere('term', 'like', '%'.$allergen->name.'%')
                    ->orWhere('term', 'like', '%'.$allergen->family.'%');
            })
            ->exists();

        if (! $documented) {
            return null;
        }

        $message = "Patient has a documented allergy to {$allergen->name}";

        if ($allergen->family !== null) {
            $message .= " ({$allergen->family} family)";
        }

        $message .= "; {$this->titleCase($item->medicine_name)} should be avoided.";

        return $this->ruleMessage(null, $message, $allergen->is_approved);
    }

    private function dosageRuleFor(string $medicineName): ?DosageRule
    {
        $name = strtolower($medicineName);

        return DosageRule::query()->get()
            ->first(fn (DosageRule $rule) => $this->matchesRule($name, $rule->medicine_name, $rule->aliases));
    }

    private function allergenFor(string $medicineName): ?Allergen
    {
        $name = strtolower($medicineName);

        return Allergen::query()->get()
            ->first(fn (Allergen $allergen) => $this->matchesRule($name, $allergen->name, $allergen->aliases));
    }

    /**
     * Match a free-text medicine name against a canonical name and aliases,
     * case-insensitively and by substring.
     *
     * @param  array<int, string>|null  $aliases
     */
    private function matchesRule(string $name, string $canonical, ?array $aliases): bool
    {
        if (str_contains($name, strtolower($canonical))) {
            return true;
        }

        foreach ($aliases ?? [] as $alias) {
            if (str_contains($name, strtolower($alias))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compose the final recommendation text, flagging rows that have not been
     * approved by the clinic's qualified clinical lead (PRD §12/§22).
     */
    private function ruleMessage(?string $guidance, string $fallback, bool $isApproved): string
    {
        $message = $guidance ?: $fallback;

        return $isApproved ? $message : $message.' (rule pending clinical review)';
    }

    /**
     * Convert a textual dosage and frequency into estimated daily milligrams.
     */
    private function parseDailyMilligrams(string $dosage, string $frequency): ?int
    {
        if (! preg_match('/(\d+(?:\.\d+)?)\s*(?:mg|gram|grams?)/i', $dosage, $matches)) {
            return null;
        }

        if (preg_match('/(\d+)\s*(?:mg|gram|grams?)/i', $frequency, $freqDose)) {
            return (int) round((float) $freqDose[1]);
        }

        return (int) round((float) $matches[1] * $this->dailyAdministrations($frequency));
    }

    private function dailyAdministrations(string $frequency): int
    {
        $frequency = strtolower($frequency);

        return match (true) {
            str_contains($frequency, 'four times daily') => 4,
            str_contains($frequency, 'three times daily'), str_contains($frequency, 'thrice') => 3,
            str_contains($frequency, 'twice daily'), str_contains($frequency, 'every 12 hours') => 2,
            str_contains($frequency, 'every 6 hours') => 4,
            str_contains($frequency, 'every 8 hours') => 3,
            str_contains($frequency, 'once daily'), str_contains($frequency, 'once a day') => 1,
            default => 1,
        };
    }

    private function createAlert(
        Patient $patient,
        User $doctor,
        string $triggerType,
        string $recommendation,
    ): ?ClinicalDecisionSupport {
        $existing = ClinicalDecisionSupport::query()
            ->where('patient_id', $patient->id)
            ->where('trigger_type', $triggerType)
            ->where('recommendation', $recommendation)
            ->where('is_acknowledged', false)
            ->exists();

        if ($existing) {
            return null;
        }

        return ClinicalDecisionSupport::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'trigger_type' => $triggerType,
            'recommendation' => $recommendation,
            'is_acknowledged' => false,
        ]);
    }

    private function titleCase(string $value): string
    {
        return Str::title($value);
    }
}
