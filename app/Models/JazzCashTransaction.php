<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single JazzCash hosted-checkout attempt for an invoice: initiated as
 * "pending", then completed, failed or cancelled by the verified gateway
 * callback. Only a successfully verified callback moves the record out of
 * "pending" — a Payment row is created exclusively from a completed record.
 */
#[Fillable(['invoice_id', 'txn_ref_no', 'amount', 'status', 'response_code', 'response_message', 'retrieval_reference_no', 'callback_payload'])]
class JazzCashTransaction extends Model
{
    protected $table = 'jazzcash_transactions';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'callback_payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
