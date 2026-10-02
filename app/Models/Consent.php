<?php

namespace App\Models;

use App\Models\Concerns\PatientBranchScope;
use Database\Factories\ConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['patient_id', 'user_id', 'consent_type', 'scope', 'is_active', 'signed_at', 'expires_at', 'withdrawn_by', 'withdrawn_at'])]
class Consent extends Model
{
    /** @use HasFactory<ConsentFactory> */
    use HasFactory;

    /**
     * Consent records are partitioned by their patient's branch.
     */
    use PatientBranchScope;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'signed_at' => 'date',
            'expires_at' => 'date',
            'withdrawn_at' => 'date',
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
     * The staff member who obtained this consent.
     *
     * @return BelongsTo<User, $this>
     */
    public function obtainedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
