<?php

namespace App\Services;

use App\Models\Allergen;
use App\Models\DosageRule;
use App\Models\DrugInteractionRule;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Reports whether the clinical safety ruleset is complete enough to detect
 * problems at all.
 *
 * `ClinicalDecisionSupportService` can only raise an alert for what is in the
 * database. An empty (or entirely unapproved) ruleset makes every prescription
 * look clean, which is a silent fail-open on the most safety-critical path in
 * the product. PRD §12/§22 requires approved reference data to be loaded before
 * clinical testing and forbids starting production with empty safety data, so
 * this check exists to make that requirement observable and enforceable.
 */
class ReferenceDataReadinessService
{
    /**
     * The rule sets the clinical decision support engine depends on.
     *
     * @var array<string, class-string<Model>>
     */
    public const REQUIRED_TYPES = [
        'allergens' => Allergen::class,
        'dosage_rules' => DosageRule::class,
        'drug_interaction_rules' => DrugInteractionRule::class,
    ];

    /**
     * Whether enforcement should currently refuse clinical writes.
     *
     * Fail-closed: in production the answer is always yes, and the config key
     * is deliberately ignored, so there is no configuration in which a live
     * clinic silently stops refusing to prescribe against an unseeded ruleset.
     * Outside production the config key can force enforcement on (to rehearse
     * production behaviour in staging) or off, which is what lets local and
     * test environments run without a seeded ruleset.
     *
     * This governs the *ruleset precondition* only. It has no effect on the
     * allergy/interaction/dosage block for an individual prescription, which is
     * unconditional and has no bypass.
     */
    public function enforcementEnabled(): bool
    {
        return self::resolveEnforcement(
            config('clinic.reference_data.require_approved_before_clinical_writes'),
            app()->isProduction(),
        );
    }

    /**
     * The enforcement decision, isolated so the contract can be asserted
     * directly rather than only through the environment.
     *
     * @param  bool|null  $configured  The config override, null when unset.
     */
    public static function resolveEnforcement(?bool $configured, bool $isProduction): bool
    {
        if ($isProduction) {
            return true;
        }

        return $configured ?? false;
    }

    /**
     * Inspect each required rule set.
     *
     * @return array{ready: bool, types: array<string, array{approved: int, total: int, ready: bool}>}
     */
    public function status(): array
    {
        $types = [];

        foreach (self::REQUIRED_TYPES as $name => $model) {
            try {
                $approved = $model::where('is_approved', true)->count();
                $total = $model::count();
            } catch (Throwable) {
                // If we cannot read the table we cannot claim the rules are
                // loaded, so report it as missing rather than defaulting open.
                $types[$name] = ['approved' => 0, 'total' => 0, 'ready' => false];

                continue;
            }

            $types[$name] = [
                'approved' => $approved,
                'total' => $total,
                'ready' => $approved > 0,
            ];
        }

        return [
            'ready' => collect($types)->every(fn (array $type) => $type['ready']),
            'types' => $types,
        ];
    }

    public function isReady(): bool
    {
        return $this->status()['ready'];
    }

    /**
     * The rule sets that have no approved row, for use in an error message.
     *
     * @return array<int, string>
     */
    public function missingTypes(): array
    {
        return collect($this->status()['types'])
            ->reject(fn (array $type) => $type['ready'])
            ->keys()
            ->all();
    }

    /**
     * Human-readable, actionable refusal reason, or null when prescription
     * creation may proceed.
     */
    public function blockingReason(): ?string
    {
        if (! $this->enforcementEnabled()) {
            return null;
        }

        if ($this->isReady()) {
            return null;
        }

        $missing = collect($this->missingTypes())->map(fn (string $type) => str_replace('_', ' ', $type))->all();

        return 'Prescribing is blocked because the clinical safety ruleset is not loaded: '
            .implode(', ', $missing)
            .'. Each of allergens, dosage rules and drug interaction rules needs at least one row approved by '
            .'the clinic\'s qualified clinical lead before prescriptions can be issued. '
            .'Load and approve the initial reference data, then retry. '
            .'(PRD §12/§22 — production must not run with empty or placeholder safety data.)';
    }
}
