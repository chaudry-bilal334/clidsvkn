<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DispenseGroup;
use App\Models\Dispensing;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;

class DispensingService
{
    public function __construct(private readonly BillingService $billing) {}

    /**
     * Dispense every requested line against FEFO (first-expiry-first-out)
     * non-expired batches. The whole prescription dispense is atomic: if any
     * line lacks stock, nothing is written.
     *
     * @param  array<int, array{medicine_name: string, quantity: int}>  $lineItems
     * @return Collection<int, Dispensing>
     */
    public function dispense(Prescription $prescription, array $lineItems, User $actor): Collection
    {
        return $this->withTransactionRetry(function () use ($prescription, $lineItems, $actor): Collection {
            /** @var Prescription $locked */
            $locked = Prescription::query()->lockForUpdate()->findOrFail($prescription->id);

            if (! in_array($locked->status, ['pending', 'in_progress', 'finalized'], true)) {
                throw ValidationException::withMessages([
                    'prescription_id' => ['Only active prescriptions can be dispensed.'],
                ]);
            }

            $dispensings = collect();

            // One dispense action = one group = one invoice. The group owns the
            // invoice link; the dispensing rows belong to it.
            $group = DispenseGroup::create([
                'prescription_id' => $locked->id,
                'created_by' => $actor->id,
            ]);

            foreach ($lineItems as $line) {
                $dispensings = $dispensings->merge(
                    $this->dispenseItem($locked, $line['medicine_name'], (int) $line['quantity'], $actor, $group),
                );
            }

            // Raise the single invoice for the whole action in the same
            // transaction. If billing fails the dispense rolls back with it.
            $this->billing->createDispenseInvoice($group, $dispensings, $actor);

            if (in_array($locked->status, ['pending', 'in_progress'], true)) {
                $locked->update(['status' => 'dispensed']);
            }

            AuditLog::record(
                $actor,
                'dispensing.created',
                $locked,
                newValues: [
                    'dispensing_count' => $dispensings->count(),
                    'items' => $lineItems,
                ],
            );

            return $dispensings;
        });
    }

    /**
     * Decrement the requested quantity across eligible batches, oldest expiry
     * first, recording a dispensing row and a stock movement per batch.
     *
     * @return Collection<int, Dispensing>
     */
    private function dispenseItem(Prescription $prescription, string $medicineName, int $quantity, User $actor, DispenseGroup $group): Collection
    {
        $item = $this->matchInventoryItem($medicineName);

        if ($item === null) {
            throw ValidationException::withMessages([
                'items' => ["No inventory item matches '{$medicineName}'."],
            ]);
        }

        $batches = $item->availableBatches()->lockForUpdate()->get();

        if ($batches->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => ["Medicine '{$medicineName}' has no available (non-expired) stock."],
            ]);
        }

        if ($quantity > (int) $batches->sum('current_quantity')) {
            throw ValidationException::withMessages([
                'items' => ["Insufficient stock for '{$medicineName}'."],
            ]);
        }

        $remaining = $quantity;
        $dispensings = collect();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((int) $batch->current_quantity, $remaining);
            $batch->decrement('current_quantity', $take);
            $remaining -= $take;

            $dispensings->push($this->record($prescription, $item, $batch->id, $take, $actor, $group));
        }

        $item->stock_quantity = $item->ledgerStock();
        $item->save();

        return $dispensings;
    }

    /**
     * Void a dispensing. Voiding any line of a group voids the WHOLE group:
     * every non-voided line's stock is returned and the group's invoice is
     * voided. This keeps the invoice correct — a partial void could otherwise
     * orphan or misstate it. If the invoice already carries payments the void
     * is blocked; a refund/reversal is required first (PRD §10).
     */
    public function void(Dispensing $dispensing, User $actor, string $reason): Dispensing
    {
        return $this->withTransactionRetry(function () use ($dispensing, $actor, $reason): Dispensing {
            /** @var Dispensing $locked */
            $locked = Dispensing::query()->lockForUpdate()->findOrFail($dispensing->id);

            $group = $locked->dispenseGroup;

            // Legacy per-line dispensing with no group: void just this line.
            if ($group === null) {
                $this->returnStock($locked, $reason);

                return $locked;
            }

            /** @var DispenseGroup $lockedGroup */
            $lockedGroup = DispenseGroup::query()->lockForUpdate()->findOrFail($group->id);

            $invoice = $lockedGroup->invoice;

            if ($invoice !== null && (float) $invoice->amount_paid > 0) {
                throw ValidationException::withMessages([
                    'dispensing' => ['This dispense has a paid invoice and cannot be voided. Issue a refund or reversal first.'],
                ]);
            }

            $linesVoided = $lockedGroup->dispensings()->whereNull('voided_at')->count();

            foreach ($lockedGroup->dispensings()->whereNull('voided_at')->get() as $line) {
                $this->returnStock($line, $reason);
            }

            if ($invoice !== null) {
                $this->billing->voidInvoice($invoice, $actor, 'Dispensing voided: '.$reason);
            }

            $previous = [
                'invoice_id' => $lockedGroup->invoice_id,
                'lines_voided' => $linesVoided,
            ];

            $lockedGroup->invoice_id = null;
            $lockedGroup->save();

            AuditLog::record(
                $actor,
                'dispensing.voided',
                $locked,
                oldValues: $previous,
                newValues: [
                    'stock_returned' => true,
                    'invoice_voided' => $invoice !== null,
                    'reason' => $reason,
                ],
            );

            return $locked;
        });
    }

    /**
     * Return a dispensed line's stock to the batch and the item ledger, and
     * record the return as a stock movement.
     */
    private function returnStock(Dispensing $dispensing, string $reason): void
    {
        /** @var Dispensing $locked */
        $locked = Dispensing::query()->lockForUpdate()->findOrFail($dispensing->id);

        if ($locked->voided_at !== null) {
            return;
        }

        $batch = $locked->batch;
        $item = $batch?->item;

        if ($batch === null || $item === null) {
            return;
        }

        $quantity = (float) $locked->quantity_dispensed;

        $batch->increment('current_quantity', $quantity);
        $item->stock_quantity = $item->ledgerStock();
        $item->save();

        InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'inventory_batch_id' => $batch->id,
            'type' => 'return',
            'quantity' => $quantity,
            'unit_price' => $item->unit_price,
            'total_amount' => round($quantity * (float) $item->unit_price, 2),
            'reason' => 'Dispensing voided: '.$reason,
        ]);

        $locked->voided_at = now();
        $locked->save();
    }

    private function record(Prescription $prescription, InventoryItem $item, int $batchId, int $take, User $actor, ?DispenseGroup $group = null): Dispensing
    {
        $dispensing = Dispensing::create([
            'prescription_id' => $prescription->id,
            'inventory_batch_id' => $batchId,
            'quantity_dispensed' => $take,
            'dispensed_by' => $actor->id,
            'dispense_group_id' => $group?->id,
        ]);

        InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'inventory_batch_id' => $batchId,
            'type' => 'dispense',
            'quantity' => $take,
            'unit_price' => $item->unit_price,
            'total_amount' => round($take * (float) $item->unit_price, 2),
            'reason' => 'Dispensed from prescription '.$prescription->prescription_number,
        ]);

        return $dispensing;
    }

    private function matchInventoryItem(string $medicineName): ?InventoryItem
    {
        $needle = Str::lower($medicineName);

        /** @var InventoryItem|null $item */
        $item = InventoryItem::query()
            ->whereRaw('LOWER(item_name) = ?', [$needle])
            ->orWhereRaw('LOWER(item_name) LIKE ?', ['%'.$needle.'%'])
            ->first();

        return $item;
    }

    /**
     * Run the dispense in a transaction, retrying transient store-level lock
     * conflicts (SQLite "database is locked", InnoDB deadlock / lock-wait
     * timeout) so a concurrent dispenser lands on a handled 4xx rather than a
     * raw driver 500.
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withTransactionRetry(callable $callback, int $attempts = 4, int $initialDelayMs = 25): mixed
    {
        $delayMs = $initialDelayMs;

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($callback);
            } catch (PDOException $e) {
                if ($attempt >= $attempts || ! $this->isTransientLockError($e)) {
                    throw $e;
                }

                usleep($delayMs * 1000);
                $delayMs *= 2;
            }
        }
    }

    /**
     * True when the failure is a retryable store-level lock conflict.
     */
    private function isTransientLockError(PDOException $e): bool
    {
        return app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e);
    }
}
