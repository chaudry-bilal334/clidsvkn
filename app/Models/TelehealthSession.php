<?php

namespace App\Models;

use App\Models\Concerns\PatientBranchScope;
use Database\Factories\TelehealthSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['patient_id', 'doctor_id', 'status'])]
class TelehealthSession extends Model
{
    /** @use HasFactory<TelehealthSessionFactory> */
    use HasFactory;

    /**
     * Sessions are partitioned by their patient's branch. Sessions brought up
     * by a cross-branch doctor require that doctor to carry the cross-branch
     * flag (which bypasses the scope), mirroring the BranchScope semantics.
     */
    use PatientBranchScope;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'string',
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

    /**
     * @return HasMany<TelehealthChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TelehealthChatMessage::class, 'session_id');
    }
}
