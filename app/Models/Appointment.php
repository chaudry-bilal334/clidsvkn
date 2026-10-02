<?php

namespace App\Models;

use App\Models\Concerns\BranchScope;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['branch_id', 'patient_id', 'doctor_id', 'scheduled_at', 'status', 'reason', 'notes', 'cancelled_at', 'cancellation_reason', 'status_changed_at'])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use BranchScope, HasFactory;

    public const STATUS_BOOKED = 'booked';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_IN_CONSULTATION = 'in_consultation';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    /**
     * The full status vocabulary (PRD §6).
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_BOOKED,
        self::STATUS_CONFIRMED,
        self::STATUS_ARRIVED,
        self::STATUS_WAITING,
        self::STATUS_IN_CONSULTATION,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /**
     * Statuses from which the appointment still occupies its slot and can
     * therefore be re-booked only after leaving one of them.
     *
     * `cancelled` and `no_show` release the slot; `completed` does too,
     * because the slot is in the past. This set drives the database-level
     * active-slot constraint (PRD §6, §11).
     *
     * @var array<int, string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_BOOKED,
        self::STATUS_CONFIRMED,
        self::STATUS_ARRIVED,
        self::STATUS_WAITING,
        self::STATUS_IN_CONSULTATION,
    ];

    /**
     * The permitted status transitions keyed by current status (PRD §6).
     *
     * `cancelled` and `no_show` are reachable from any state that has not yet
     * reached a terminal outcome. `completed`, `cancelled` and `no_show` are
     * terminal — corrections happen through the void/amend family on the
     * encounter, not by rewinding the appointment.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_BOOKED => [
            self::STATUS_CONFIRMED,
            self::STATUS_ARRIVED,
            self::STATUS_CANCELLED,
            self::STATUS_NO_SHOW,
        ],
        self::STATUS_CONFIRMED => [
            self::STATUS_ARRIVED,
            self::STATUS_CANCELLED,
            self::STATUS_NO_SHOW,
        ],
        self::STATUS_ARRIVED => [
            self::STATUS_WAITING,
            self::STATUS_IN_CONSULTATION,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_WAITING => [
            self::STATUS_IN_CONSULTATION,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_IN_CONSULTATION => [
            self::STATUS_COMPLETED,
        ],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_NO_SHOW => [],
    ];

    /**
     * Statuses that close the appointment lifecycle.
     *
     * @var array<int, string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * Whether the appointment still holds its (doctor, slot) reservation.
     */
    public function holdsSlot(): bool
    {
        return in_array((string) $this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * The statuses this appointment may legally move to next.
     *
     * @return array<int, string>
     */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[(string) $this->status] ?? [];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasOne<QueueEntry, $this>
     */
    public function queueEntry(): HasOne
    {
        return $this->hasOne(QueueEntry::class);
    }
}
