<?php

namespace App\Models;

use App\Models\Concerns\BranchScope;
use Database\Factories\QueueEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['appointment_id', 'patient_id', 'branch_id', 'status', 'checked_in_at'])]
class QueueEntry extends Model
{
    /**
     * Queue entries carry their own tenant column and are partitioned
     * directly by branch.
     */
    use BranchScope;

    /** @use HasFactory<QueueEntryFactory> */
    use HasFactory;

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_IN_CONSULTATION = 'in_consultation';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ARRIVED,
        self::STATUS_WAITING,
        self::STATUS_IN_CONSULTATION,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The permitted queue status transitions keyed by current status.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_ARRIVED => [self::STATUS_WAITING, self::STATUS_CANCELLED],
        self::STATUS_WAITING => [self::STATUS_IN_CONSULTATION, self::STATUS_CANCELLED],
        self::STATUS_IN_CONSULTATION => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
