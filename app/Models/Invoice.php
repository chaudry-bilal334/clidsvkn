<?php

namespace App\Models;

use App\Models\Concerns\BranchScope;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['branch_id', 'invoice_number', 'patient_id', 'patient_name', 'status', 'subtotal', 'tax', 'total', 'amount_paid', 'balance_due', 'issued_at', 'finalized_at', 'finalized_by', 'reversal_of', 'voided_at', 'void_reason'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BranchScope, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_FINALIZED = 'finalized';

    /**
     * A voided invoice is a terminal, non-payable state. Corrections are made
     * by issuing a replacement invoice that references this one.
     */
    public const STATUS_VOIDED = 'voided';

    /**
     * A finalized invoice's figures are frozen (PRD §7). Any later correction
     * must be a linked reversal invoice, never an in-place edit.
     */
    public function isFinalized(): bool
    {
        return $this->finalized_at !== null || $this->status === self::STATUS_FINALIZED;
    }

    /**
     * Defense in depth: a finalized invoice must never be deleted, even by
     * direct Eloquent calls that bypass the service layer. Updates are allowed
     * because the legitimate reversal path (BillingService::reversePayment)
     * writes to the invoice; the service-layer guard is the primary defense
     * against unauthorized updates.
     */
    protected static function booted(): void
    {
        static::deleting(function (Invoice $invoice): void {
            if ($invoice->isFinalized()) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['A finalized invoice cannot be deleted. Corrections use a linked reversal or refund.'],
                ]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'issued_at' => 'datetime',
            'finalized_at' => 'datetime',
            'voided_at' => 'datetime',
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
