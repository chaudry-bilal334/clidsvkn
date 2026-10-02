<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['invoice_id', 'amount', 'method', 'reference', 'txn_ref', 'status', 'received_by', 'branch_id', 'reversal_of', 'paid_at', 'finalized_at', 'finalized_by'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * A payment that is on the books.
     */
    public const STATUS_RECORDED = 'recorded';

    /**
     * A payment that has been backed out by a linked reversal entry.
     */
    public const STATUS_REVERSED = 'reversed';

    /**
     * A payment that has been locked into a finalized invoice and can no
     * longer be edited or reversed without reopening the invoice.
     */
    public const STATUS_FINALIZED = 'finalized';

    public const METHOD_CASH = 'cash';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_CARD = 'card';

    public const METHOD_JAZZCASH = 'jazzcash';

    public const METHOD_EASYPAISA = 'easypaisa';

    /**
     * All accepted payment methods — the single source of truth for how a
     * payment was recorded. Live gateway integrations (card/JazzCash/
     * EasyPaisa APIs) are pending the merchant-account decision and will
     * map onto these values once selected.
     *
     * @var list<string>
     */
    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_BANK_TRANSFER,
        self::METHOD_CARD,
        self::METHOD_JAZZCASH,
        self::METHOD_EASYPAISA,
    ];

    public static function isValidMethod(string $method): bool
    {
        return in_array($method, self::METHODS, true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    /**
     * A payment can only be reversed while it is still an open entry.
     */
    public function isReversible(): bool
    {
        return $this->status === self::STATUS_RECORDED && $this->reversal_of === null;
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
