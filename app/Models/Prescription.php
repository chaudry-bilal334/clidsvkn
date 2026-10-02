<?php

namespace App\Models;

use App\Models\Concerns\PatientBranchScope;
use Database\Factories\PrescriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'prescription_number',
    'patient_id',
    'patient_name',
    'doctor_id',
    'doctor_name',
    'doctor_license_no',
    'appointment_id',
    'status',
    'notes',
    'issued_at',
    'finalized_at',
    'finalized_by',
    'voided_at',
    'voided_by',
    'void_reason',
    'amends_prescription_id',
])]
class Prescription extends Model
{
    /** @use HasFactory<PrescriptionFactory> */
    use HasFactory;

    /**
     * Prescription records are partitioned by their patient's branch so cross
     * branch staff cannot read another branch's prescription by ID.
     */
    use PatientBranchScope;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_DISPENSED = 'dispensed';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_VOIDED = 'voided';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'finalized_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isDispensed(): bool
    {
        return $this->status === self::STATUS_DISPENSED;
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
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function amends(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'amends_prescription_id');
    }

    /**
     * @return HasMany<PrescriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    /**
     * @return HasMany<Dispensing, $this>
     */
    public function dispensings(): HasMany
    {
        return $this->hasMany(Dispensing::class);
    }
}
