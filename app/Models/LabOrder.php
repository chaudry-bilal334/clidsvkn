<?php

namespace App\Models;

use App\Models\Concerns\BranchScope;
use Database\Factories\LabOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'patient_id', 'test_name', 'status', 'result', 'recorded_at', 'recorded_by', 'verifier_id', 'verification_state', 'verified_at', 'finalized_at', 'finalized_by', 'sample_collected_at', 'sample_collected_by', 'sample_identifier'])]
class LabOrder extends Model
{
    /** @use HasFactory<LabOrderFactory> */
    use BranchScope, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SAMPLE_COLLECTED = 'sample_collected';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const VERIFICATION_PENDING = 'pending_verification';

    public const VERIFICATION_VERIFIED = 'verified';

    public const VERIFICATION_AMENDED = 'amended';

    public const VERIFICATION_STATES = [
        self::VERIFICATION_PENDING,
        self::VERIFICATION_VERIFIED,
        self::VERIFICATION_AMENDED,
    ];

    /**
     * Verification states whose result is signed off and may be released to
     * the patient.
     *
     * `pending_verification` is excluded because a patient must never read a
     * result the lab has not confirmed. `amended` is excluded for a subtler
     * reason: an amendment deliberately clears the verifier, so the amended
     * value is back to awaiting a second person. Releasing it would publish an
     * unverified result as if it were final.
     *
     * @var list<string>
     */
    public const RELEASABLE_VERIFICATION_STATES = [
        self::VERIFICATION_VERIFIED,
    ];

    /**
     * Verification states a lab order can be re-verified from. An amended
     * result is awaiting a fresh second pair of eyes, not settled.
     *
     * @var list<string>
     */
    public const REVERIFIABLE_STATES = [
        self::VERIFICATION_PENDING,
        self::VERIFICATION_AMENDED,
    ];

    /**
     * Whether this result has passed verification and may be shown to the
     * patient in the portal.
     */
    public function isReleasableToPatient(): bool
    {
        return in_array($this->verification_state, self::RELEASABLE_VERIFICATION_STATES, true)
            && $this->result !== null
            && $this->result !== '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'verified_at' => 'datetime',
            'finalized_at' => 'datetime',
            'sample_collected_at' => 'datetime',
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifier_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sampleCollectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sample_collected_by');
    }
}
