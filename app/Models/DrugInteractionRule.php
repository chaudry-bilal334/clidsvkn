<?php

namespace App\Models;

use Database\Factories\DrugInteractionRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['medicine_a', 'medicine_b', 'aliases_a', 'aliases_b', 'severity', 'guidance', 'is_approved', 'approved_by', 'approved_at'])]
class DrugInteractionRule extends Model
{
    /** @use HasFactory<DrugInteractionRuleFactory> */
    use HasFactory;

    public const SEVERITY_CONTRAINDICATED = 'contraindicated';

    public const SEVERITY_SEVERE = 'severe';

    public const SEVERITY_MODERATE = 'moderate';

    public const SEVERITY_MINOR = 'minor';

    /**
     * @return array<int, string>
     */
    public static function severities(): array
    {
        return [self::SEVERITY_CONTRAINDICATED, self::SEVERITY_SEVERE, self::SEVERITY_MODERATE, self::SEVERITY_MINOR];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases_a' => 'array',
            'aliases_b' => 'array',
            'is_approved' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
