<?php

namespace App\Models;

use App\Models\Concerns\PatientBranchScope;
use Database\Factories\PatientMedicalHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['patient_id', 'category', 'allergen_id', 'term', 'reaction', 'severity', 'onset_date', 'is_active', 'recorded_by', 'notes'])]
class PatientMedicalHistory extends Model
{
    /** @use HasFactory<PatientMedicalHistoryFactory> */
    use HasFactory;

    /**
     * Patient clinical records are partitioned by their patient's branch so
     * cross-branch staff cannot read or mutate another branch's history by ID.
     */
    use PatientBranchScope;

    public const CATEGORY_ALLERGY = 'allergy';

    public const CATEGORY_CONDITION = 'condition';

    public const CATEGORY_SURGERY = 'surgery';

    public const CATEGORY_FAMILY_HISTORY = 'family_history';

    public const CATEGORY_OTHER = 'other';

    public const SEVERITY_MILD = 'mild';

    public const SEVERITY_MODERATE = 'moderate';

    public const SEVERITY_SEVERE = 'severe';

    public const SEVERITY_UNKNOWN = 'unknown';

    /**
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return [self::CATEGORY_ALLERGY, self::CATEGORY_CONDITION, self::CATEGORY_SURGERY, self::CATEGORY_FAMILY_HISTORY, self::CATEGORY_OTHER];
    }

    /**
     * @return array<int, string>
     */
    public static function severities(): array
    {
        return [self::SEVERITY_MILD, self::SEVERITY_MODERATE, self::SEVERITY_SEVERE, self::SEVERITY_UNKNOWN];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'onset_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Allergen, $this>
     */
    public function allergen(): BelongsTo
    {
        return $this->belongsTo(Allergen::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
