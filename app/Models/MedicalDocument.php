<?php

namespace App\Models;

use App\Models\Concerns\BranchScope;
use App\Services\QueryCacheService;
use Database\Factories\MedicalDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['branch_id', 'patient_id', 'uploaded_by', 'category', 'original_name', 'file_path', 'mime_type', 'size_bytes'])]
class MedicalDocument extends Model
{
    /** @use HasFactory<MedicalDocumentFactory> */
    use BranchScope, HasFactory, SoftDeletes;

    /**
     * Supported document categories.
     *
     * @var array<int, string>
     */
    public const CATEGORIES = ['lab_report', 'external_prescription', 'insurance_card', 'other'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_by' => 'integer',
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Force the caches that depend on patient documents to refresh.
     */
    public static function invalidateCaches(): void
    {
        app(QueryCacheService::class)->forgetGroup('medical.documents');
    }

    /**
     * Register model event hooks.
     */
    protected static function booted(): void
    {
        static::saved(static fn () => static::invalidateCaches());
        static::deleted(static fn () => static::invalidateCaches());
    }
}
