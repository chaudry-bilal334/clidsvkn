<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DispenseGroup;
use App\Models\Dispensing;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Settling an invoice charge (PRD §7, §11, §17).
 *
 * Every balance mutation lives here rather than in the controller so that the
 * three things that must never drift apart are written in one place and one
 * transaction: the payment row, the invoice's `amount_paid` / `balance_due`,
 * and the audit entry.
 */
class BillingService
{
    /**
     * Record a payment against an invoice and re-derive the balance.
     *
     * The invoice row is locked for the duration of the transaction. Without
     * it, two concurrent cashiers both read the same `balance_due`, both pass
     * the over-payment guard, and both write `amount_paid = old + their
     * amount` — silently discarding one of the payments while reporting
     * success for both.
     */
    public function recordPayment(Invoice $invoice, float $amount, string $method, User $actor, ?string $transactionReference = null): Payment
    {
        if (! Payment::isValidMethod($method)) {
            throw ValidationException::withMessages([
                'method' => ['The selected payment method is not supported.'],
            ]);
        }

        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['The payment amount must be greater than zero.'],
            ]);
        }

        try {
            return DB::transaction(function () use ($invoice, $amount, $method, $actor, $transactionReference): Payment {
                /** @var Invoice $locked */
                $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

                if ($locked->status === Invoice::STATUS_VOIDED) {
                    throw ValidationException::withMessages([
                        'invoice_id' => ['A voided invoice cannot accept payments.'],
                    ]);
                }

                if ($locked->isFinalized()) {
                    throw ValidationException::withMessages([
                        'invoice_id' => ['A finalized invoice cannot accept payments. Issue a refund or reversal instead.'],
                    ]);
                }

                if ($locked->balance_due <= 0) {
                    throw ValidationException::withMessages([
                        'invoice_id' => ['This invoice has no outstanding balance.'],
                    ]);
                }

                // Re-checked under the lock: the caller may have been reading a
                // stale balance when it validated.
                if ($amount > (float) $locked->balance_due) {
                    throw ValidationException::withMessages([
                        'amount' => ['The payment amount exceeds the balance due on this invoice.'],
                    ]);
                }

                $payment = Payment::create([
                    'invoice_id' => $locked->id,
                    'amount' => $amount,
                    'method' => $method,
                    'reference' => $transactionReference,
                    'txn_ref' => $transactionReference,
                    'status' => Payment::STATUS_RECORDED,
                    'received_by' => $actor->id,
                    'branch_id' => $locked->branch_id,
                    'paid_at' => now(),
                ]);

                $amountPaid = round((float) $locked->amount_paid + $amount, 2);
                $balanceDue = round((float) $locked->total - $amountPaid, 2);

                $locked->amount_paid = $amountPaid;
                $locked->balance_due = max(0, $balanceDue);

                if ($locked->balance_due <= 0) {
                    $locked->status = Invoice::STATUS_PAID;
                }

                $locked->save();

                AuditLog::record(
                    $actor,
                    'payment.recorded',
                    $payment,
                    newValues: [
                        'invoice_id' => $locked->id,
                        'invoice_number' => $locked->invoice_number,
                        'amount' => $amount,
                        'method' => $method,
                        'txn_ref' => $transactionReference,
                        'amount_paid' => $amountPaid,
                        'balance_due' => $locked->balance_due,
                    ],
                );

                return $payment;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The unique(method, txn_ref) index is what stops a replayed
            // gateway webhook from settling the same charge twice.
            throw ValidationException::withMessages([
                'reference' => ['This transaction reference has already been recorded against a payment.'],
            ]);
        }
    }

    /**
     * Reverse a recorded payment by writing a linked negative payment.
     *
     * The original row is retained: PRD §11 requires a reversal to be visible
     * as its own entry, not an edit or a delete.
     */
    public function reversePayment(Payment $payment, User $actor, string $reason): Payment
    {
        if ($payment->status === Payment::STATUS_REVERSED) {
            throw ValidationException::withMessages([
                'payment_id' => ['This payment has already been reversed.'],
            ]);
        }

        return DB::transaction(function () use ($payment, $actor, $reason): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($locked->invoice_id);

            $reversal = Payment::create([
                'invoice_id' => $invoice->id,
                'amount' => -1 * (float) $locked->amount,
                'method' => $locked->method,
                'reference' => $reason,
                'txn_ref' => null,
                'status' => Payment::STATUS_REVERSED,
                'received_by' => $actor->id,
                'branch_id' => $locked->branch_id,
                'reversal_of' => $locked->id,
                'paid_at' => now(),
            ]);

            $locked->status = Payment::STATUS_REVERSED;
            $locked->save();

            $amountPaid = max(0, round((float) $invoice->amount_paid - (float) $locked->amount, 2));
            $balanceDue = round((float) $invoice->total - $amountPaid, 2);

            $invoice->amount_paid = $amountPaid;
            $invoice->balance_due = $balanceDue;
            $invoice->status = Invoice::STATUS_GENERATED;
            $invoice->save();

            AuditLog::record(
                $actor,
                'payment.reversed',
                $reversal,
                oldValues: [
                    'payment_id' => $locked->id,
                    'amount' => (float) $locked->amount,
                ],
                newValues: [
                    'reversal_of' => $locked->id,
                    'reason' => $reason,
                    'amount_paid' => $amountPaid,
                    'balance_due' => $balanceDue,
                ],
            );

            return $reversal;
        });
    }

    /**
     * Raise ONE invoice for a whole dispense action (a group of dispensing
     * rows across medicines and FEFO batches). Every figure is derived on the
     * server from the inventory item's unit price — the client never supplies
     * a price, total, or status. The invoice is created in the caller's
     * transaction so a billing failure rolls the dispense back with it.
     *
     * The group is linked to the invoice (dispense_groups.invoice_id) and that
     * link is unique, so a retried dispense can never raise a second invoice.
     *
     * One line item per medicine; the batch detail stays on the dispensing
     * rows. All batches of an item share its unit_price, so aggregating the
     * quantity across batches is exact.
     *
     * @param  Collection<int, Dispensing>  $dispensings
     */
    public function createDispenseInvoice(DispenseGroup $group, $dispensings, User $actor): Invoice
    {
        /** @var DispenseGroup $locked */
        $locked = DispenseGroup::query()->lockForUpdate()->findOrFail($group->id);

        if ($locked->invoice_id !== null) {
            return $locked->invoice;
        }

        $prescription = $locked->prescription;

        if ($prescription === null) {
            throw ValidationException::withMessages([
                'dispense_group' => ['The dispensed prescription can no longer be resolved for billing.'],
            ]);
        }

        // Aggregate the dispensed quantity per medicine (item), keeping the
        // batch-level detail on the dispensing rows.
        $byItem = $dispensings
            ->groupBy(fn (Dispensing $dispensing): ?int => $dispensing->batch?->inventory_item_id)
            ->reject(fn ($group): bool => $group->first()?->batch?->item === null);

        if ($byItem->isEmpty()) {
            throw ValidationException::withMessages([
                'dispense_group' => ['No dispensed stock can be resolved for billing.'],
            ]);
        }

        $subtotal = 0.0;
        $lines = [];

        foreach ($byItem as $itemDispensings) {
            $item = $itemDispensings->first()->batch->item;
            $quantity = (float) $itemDispensings->sum('quantity_dispensed');
            $unitPrice = round((float) $item->unit_price, 2);
            $lineTotal = round($quantity * $unitPrice, 2);
            $subtotal = round($subtotal + $lineTotal, 2);

            $lines[] = [
                'description' => $item->item_name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        $invoice = Invoice::create([
            'invoice_number' => 'TMP-'.Str::uuid(),
            'patient_id' => $prescription->patient_id,
            'patient_name' => $prescription->patient_name,
            'status' => Invoice::STATUS_GENERATED,
            'subtotal' => $subtotal,
            'tax' => 0,
            'total' => $subtotal,
            'amount_paid' => 0,
            'balance_due' => $subtotal,
            'issued_at' => now(),
        ]);

        $invoice->invoice_number = sprintf('INV-%05d', $invoice->id);
        $invoice->save();

        foreach ($lines as $line) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
            ]);
        }

        $locked->invoice_id = $invoice->id;
        $locked->save();

        AuditLog::record(
            $actor,
            'invoice.created',
            $invoice,
            newValues: [
                'dispense_group_id' => $locked->id,
                'prescription_id' => $prescription->id,
                'invoice_number' => $invoice->invoice_number,
                'line_count' => count($lines),
                'subtotal' => $subtotal,
                'tax' => 0,
                'total' => $subtotal,
            ],
        );

        return $invoice;
    }

    /**
     * Void an invoice that has not been paid. A voided invoice is terminal and
     * non-payable; corrections are a linked reversal, never an in-place edit.
     */
    public function voidInvoice(Invoice $invoice, User $actor, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === Invoice::STATUS_VOIDED) {
                return $locked;
            }

            if ((float) $locked->amount_paid > 0) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['This invoice has payments and cannot be voided. Issue a refund or reversal first.'],
                ]);
            }

            $locked->status = Invoice::STATUS_VOIDED;
            $locked->voided_at = now();
            $locked->void_reason = $reason;
            $locked->save();

            AuditLog::record(
                $actor,
                'invoice.voided',
                $locked,
                newValues: [
                    'status' => Invoice::STATUS_VOIDED,
                    'void_reason' => $reason,
                    'total' => (float) $locked->total,
                ],
            );

            return $locked;
        });
    }

    /**
     * Finalize an invoice. Once finalized the money figures are frozen, so the
     * later corrections have to be a void-and-reissue rather than an edit.
     */
    public function finalizeInvoice(Invoice $invoice, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->finalized_at !== null) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['This invoice is already finalized.'],
                ]);
            }

            $locked->status = Invoice::STATUS_FINALIZED;
            $locked->finalized_at = now();
            $locked->finalized_by = $actor->id;
            $locked->save();

            AuditLog::record(
                $actor,
                'invoice.finalized',
                $locked,
                newValues: [
                    'status' => Invoice::STATUS_FINALIZED,
                    'total' => (float) $locked->total,
                    'balance_due' => (float) $locked->balance_due,
                ],
            );

            return $locked;
        });
    }
}
