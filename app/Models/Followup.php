<?php

namespace App\Models;

use App\Models\Concerns\BranchScope;
use Database\Factories\FollowupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'patient_id', 'lead_id', 'encounter_id', 'created_by', 'assigned_to', 'due_at', 'type', 'status', 'notes', 'completed_at', 'completed_by'])]
class Followup extends Model
{
    /** @use HasFactory<FollowupFactory> */
    use BranchScope, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_GENERAL = 'general';

    public const TYPE_REVIEW = 'review';

    public const TYPE_REFILL = 'refill';

    public const TYPE_TEST_RESULT = 'test_result';

    public const TYPE_CALL = 'call';

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_COMPLETED, self::STATUS_CANCELLED];
    }

    /**
     * @return array<int, string>
     */
    public static function types(): array
    {
        return [self::TYPE_GENERAL, self::TYPE_REVIEW, self::TYPE_REFILL, self::TYPE_TEST_RESULT, self::TYPE_CALL];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)->where('due_at', '<', now());
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)->where('due_at', '>=', now());
    }

    public function scopeForAssignee(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_to', $userId);
    }
}
