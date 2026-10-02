<?php

namespace App\Models;

use App\Models\Concerns\PatientBranchScope;
use Database\Factories\MedicationReconciliationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['patient_id', 'encounter_id', 'medicine_name', 'dosage', 'frequency', 'source', 'is_active', 'reconciled_at', 'reconciled_by', 'notes'])]
class MedicationReconciliation extends Model
{
    /** @use HasFactory<MedicationReconciliationFactory> */
    use HasFactory;

    /**
     * Mirrors the column default so a freshly created entry reports version 1
     * in memory rather than 0 until it is reloaded. The update path compares
     * this value, so a 0 here would make the first update look like a conflict.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
    ];

    /**
     * Reconciliation records are partitioned by their patient's branch.
     */
    use PatientBranchScope;

    public const SOURCE_SELF_REPORTED = 'self_reported';

    public const SOURCE_PRESCRIPTION_SNAPSHOT = 'prescription_snapshot';

    public const SOURCE_CLINICIAN = 'clinician';

    public const SOURCE_ADMISSION = 'admission';

    public const SOURCE_DISCHARGE = 'discharge';

    /**
     * @return array<int, string>
     */
    public static function sources(): array
    {
        return [self::SOURCE_SELF_REPORTED, self::SOURCE_PRESCRIPTION_SNAPSHOT, self::SOURCE_CLINICIAN, self::SOURCE_ADMISSION, self::SOURCE_DISCHARGE];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'reconciled_at' => 'datetime',
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
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
