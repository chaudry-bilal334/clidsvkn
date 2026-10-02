<?php

namespace App\Models;

use App\Models\Concerns\BranchScope;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['branch_id', 'name', 'email', 'phone', 'service_interest', 'preferred_doctor_id', 'preferred_date', 'preferred_time', 'source', 'status', 'notes', 'assigned_to'])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use BranchScope, HasFactory, SoftDeletes;

    public const STATUS_NEW = 'new';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_CLOSED_LOST = 'closed_lost';

    public const SOURCE_WEBSITE = 'website';

    public const SOURCE_REFERRAL = 'referral';

    public const SOURCE_WALK_IN = 'walk_in';

    public const SOURCE_CALL = 'call';

    public const SOURCE_EVENT = 'event';

    public const SOURCE_OTHER = 'other';

    /**
     * The approved CRM pipeline (DESIGN §15).
     *
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_NEW,
            self::STATUS_CONTACTED,
            self::STATUS_QUALIFIED,
            self::STATUS_CONVERTED,
            self::STATUS_CLOSED_LOST,
        ];
    }

    /**
     * Lead acquisition sources.
     *
     * @return array<int, string>
     */
    public static function sources(): array
    {
        return [self::SOURCE_WEBSITE, self::SOURCE_REFERRAL, self::SOURCE_WALK_IN, self::SOURCE_CALL, self::SOURCE_EVENT, self::SOURCE_OTHER];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_date' => 'date',
        ];
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
    public function preferredDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preferred_doctor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<Communication, $this>
     */
    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeNew(Builder $query): Builder
    {
        return $query->status(self::STATUS_NEW);
    }

    public function scopeContacted(Builder $query): Builder
    {
        return $query->status(self::STATUS_CONTACTED);
    }

    public function scopeQualified(Builder $query): Builder
    {
        return $query->status(self::STATUS_QUALIFIED);
    }

    public function scopeConverted(Builder $query): Builder
    {
        return $query->status(self::STATUS_CONVERTED);
    }

    public function scopeClosedLost(Builder $query): Builder
    {
        return $query->status(self::STATUS_CLOSED_LOST);
    }
}
