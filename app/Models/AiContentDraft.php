<?php

namespace App\Models;

use App\Models\Concerns\BranchScope;
use Database\Factories\AiContentDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id', 'title', 'content_type', 'content', 'status',
    'created_by', 'submitted_by', 'submitted_at',
    'reviewed_by', 'reviewed_at', 'rejection_reason', 'published_at',
])]
class AiContentDraft extends Model
{
    /** @use HasFactory<AiContentDraftFactory> */
    use BranchScope, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PUBLISHED = 'published';

    /**
     * Allow-listed, strictly non-clinical content types. Any clinical material
     * (dosage guidance, drug interactions, allergens, reference data) is out of
     * scope for the AI content workflow and must never be routed through it.
     */
    public const CONTENT_TYPES = [
        'announcement',
        'faq',
        'web_article',
        'email_template',
        'sms_template',
        'notification',
    ];

    /**
     * Distinctive clinical tokens that never belong in non-clinical AI content.
     */
    public const CLINICAL_TOKENS = [
        'dosage',
        'dosing',
        'contraindication',
        'drug interaction',
        'allergen',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Whether the supplied title/content trips the non-clinical guardrail.
     */
    public static function containsClinicalMaterial(string $title, ?string $content = null): bool
    {
        $haystack = strtolower($title.($content !== null ? " {$content}" : ''));

        foreach (self::CLINICAL_TOKENS as $token) {
            if (str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }
}
