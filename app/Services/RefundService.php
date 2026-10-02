<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundService
{
    /**
     * Record a refund as an immutable reversal ledger entry. The original
     * invoice and payment rows are never rewritten; running totals are
     * recomputed as a projection and the refund row is the source of truth.
     */
    public function processRefund(Invoice $invoice, User $actor, float $amount, string $reason, ?Payment $payment = null): Refund
    {
        return DB::transaction(function () use ($invoice, $actor, $amount, $reason, $payment): Refund {
            /** @var Invoice $locked */
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            $amount = round($amount, 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['The refund amount must be greater than zero.'],
                ]);
            }

            if ($payment !== null && (int) $payment->invoice_id !== (int) $locked->id) {
                throw ValidationException::withMessages([
                    'payment_id' => ['The payment does not belong to this invoice.'],
                ]);
            }

            $refundable = $payment === null
                ? (float) $locked->amount_paid - (float) $locked->refunds()
                    ->whereNull('payment_id')
                    ->sum('amount')
                : min(
                    (float) $locked->amount_paid,
                    (float) $payment->amount - (float) $payment->refunds()->sum('amount'),
                );

            if ($amount > $refundable) {
                throw ValidationException::withMessages([
                    'amount' => ['The refund amount exceeds the refundable amount on this invoice.'],
                ]);
            }

            $newAmountPaid = round((float) $locked->amount_paid - $amount, 2);
            $newBalanceDue = round((float) $locked->total - $newAmountPaid, 2);

            $refund = Refund::create([
                'invoice_id' => $locked->id,
                'payment_id' => $payment?->id,
                'amount' => $amount,
                'reason' => $reason,
                'processed_by' => $actor->id,
            ]);

            $locked->update([
                'amount_paid' => $newAmountPaid,
                'balance_due' => $newBalanceDue,
                'status' => $this->recomputeStatus($locked->status, $newAmountPaid, $newBalanceDue),
            ]);

            AuditLog::record(
                $actor,
                'refund.created',
                $refund,
                newValues: [
                    'invoice_id' => $locked->id,
                    'payment_id' => $payment?->id,
                    'amount' => $amount,
                    'reason' => $reason,
                ],
            );

            return $refund->load('invoice', 'payment', 'processedBy');
        });
    }

    private function recomputeStatus(string $currentStatus, float $amountPaid, float $balanceDue): string
    {
        if ($currentStatus === Invoice::STATUS_FINALIZED) {
            return Invoice::STATUS_FINALIZED;
        }

        if ($balanceDue <= 0) {
            return Invoice::STATUS_PAID;
        }

        if ($currentStatus === Invoice::STATUS_OVERDUE) {
            return Invoice::STATUS_OVERDUE;
        }

        return Invoice::STATUS_GENERATED;
    }
}
