<?php

namespace App\Models;

use App\Models\Concerns\PatientBranchScope;
use Database\Factories\ClinicalDecisionSupportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['patient_id', 'doctor_id', 'trigger_type', 'recommendation', 'is_acknowledged'])]
class ClinicalDecisionSupport extends Model
{
    /** @use HasFactory<ClinicalDecisionSupportFactory> */
    use HasFactory;

    /**
     * Patient-level CDSS records are partitioned by their patient's branch.
     */
    use PatientBranchScope;

    public const TRIGGER_DRUG_INTERACTION = 'drug_interaction';

    public const TRIGGER_ALLERGY_WARNING = 'allergy_warning';

    public const TRIGGER_DOSAGE_CHECK = 'dosage_check';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_acknowledged' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
