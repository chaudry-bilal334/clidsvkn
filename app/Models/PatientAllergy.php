<?php

namespace App\Models;

use App\Models\Concerns\PatientBranchScope;
use Database\Factories\PatientAllergyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['patient_id', 'allergen_id', 'reaction', 'severity', 'is_active', 'first_reaction_at'])]
class PatientAllergy extends Model
{
    /** @use HasFactory<PatientAllergyFactory> */
    use HasFactory;

    /**
     * Allergy records are partitioned by their patient's branch.
     */
    use PatientBranchScope;

    /**
     * @return BelongsTo<Allergen, $this>
     */
    public function allergen(): BelongsTo
    {
        return $this->belongsTo(Allergen::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
