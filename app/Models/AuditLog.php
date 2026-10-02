<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address'])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Persist an auditable action for the given actor and record.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function record(
        ?User $user,
        string $action,
        Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
    ): self {
        return static::create([
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Morph type recorded for a system-level action that owns no record.
     *
     * `audit_logs.auditable_type` / `auditable_id` are both NOT NULL, so an
     * infrastructure event cannot simply leave them empty.
     */
    public const SYSTEM_MORPH = 'system';

    /**
     * Record a system-level action that has no owning record.
     *
     * Infrastructure events (cache warmed, a backup generation that failed
     * before a row existed) still have to be auditable, but there is no model
     * to hang them off. They are stored against the `system` morph type with
     * `auditable_id` 0 so they can be told apart from a real record — casting
     * an arbitrary one in would misattribute the event.
     *
     * @param  array<string, mixed>|null  $newValues
     */
    public static function recordSystem(?User $user, string $action, ?array $newValues = null): self
    {
        return static::create([
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => self::SYSTEM_MORPH,
            'auditable_id' => 0,
            'old_values' => null,
            'new_values' => $newValues,
            'ip_address' => null,
        ]);
    }
}
