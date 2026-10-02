<?php

namespace App\Http\Controllers\Api;

use App\Events\LowStockAlertTriggered;
use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;

class InventoryController extends Controller
{
    private const TYPES = ['purchase', 'dispense', 'adjustment', 'return'];

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => InventoryItem::query()
                ->orderBy('item_name')
                ->get()
                ->map(fn (InventoryItem $item) => $this->presentItem($item))
                ->values(),
        ]);
    }

    public function transactions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => InventoryTransaction::query()
                ->with('item')
                // inventory_transactions has no branch_id column: scope through
                // the branch-scoped parent instead. whereHas('item') applies
                // InventoryItem's BranchScope to the subquery, so cross-branch
                // transactions never surface in another branch's list.
                ->whereHas('item')
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn (InventoryTransaction $transaction) => $this->presentTransaction($transaction))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $item = InventoryItem::findOrFail($validated['item_id']);
        $lockedItem = null;

        try {
            $transaction = $this->withTransactionRetry(function () use ($validated, $item, &$lockedItem): InventoryTransaction {
                $lockedItem = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);

                $quantity = (float) $validated['quantity'];
                $type = $validated['type'];

                $batchId = in_array($type, ['dispense', 'adjustment'], true)
                    ? $this->applyOutflow($lockedItem, $quantity)
                    : $this->applyInflow($lockedItem, $quantity);

                $unitPrice = $type === 'adjustment'
                    ? (float) ($validated['unit_price'] ?? 0)
                    : (float) $lockedItem->unit_price;

                $ledgerTransaction = InventoryTransaction::create([
                    'inventory_item_id' => $lockedItem->id,
                    'inventory_batch_id' => $batchId,
                    'type' => $type,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => round($quantity * $unitPrice, 2),
                    'reason' => $validated['reason'] ?? null,
                ]);

                $lockedItem->stock_quantity = $lockedItem->ledgerStock();
                if (in_array($type, ['purchase', 'return'], true)) {
                    $lockedItem->last_restocked = now()->toDateString();
                }
                $lockedItem->save();

                return $ledgerTransaction;
            });
        } catch (PDOException $e) {
            // Even after the retry budget, the underlying store still reports a
            // lock conflict (SQLite "database is locked", InnoDB deadlock or
            // lock-wait timeout). Surface a clean 409 so concurrent dispensers
            // never see a raw driver 500.
            if ($this->isTransientLockError($e)) {
                abort(409, 'This inventory record is busy; another transaction is updating it. Please retry.');
            }

            throw $e;
        }

        if ($lockedItem->isLowStock()) {
            LowStockAlertTriggered::dispatch(
                ['id' => $lockedItem->id, 'name' => $lockedItem->item_name, 'sku' => $lockedItem->sku],
                $lockedItem->stock_quantity,
                $lockedItem->reorder_level,
            );
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentTransaction($transaction),
        ], 201);
    }

    /**
     * Run $callback inside a transaction, retrying when the underlying store
     * reports a transient concurrency lock error.
     *
     * SQLite reports a busy store as `SQLSTATE[HY000]` / driverCode 5
     * ("database is locked") — with busy_timeout=0 it is thrown immediately
     * and deterministically. MySQL/InnoDB instead blocks on the row lock and
     * only errors after the lock-wait timeout (1205) or on a deadlock (1213),
     * both of which are equally retryable. Applying the same retry here keeps
     * the behavior driver-independent.
     *
     * @template T
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
     *
     * Delegates to Laravel's ConcurrencyErrorDetector, whose message-based
     * matching covers all three shapes that escape here:
     *   - SQLite:  "database is locked" (driverCode 5)
     *   - InnoDB:  "deadlock" (driverCode 1213 / SQLSTATE 40001),
     *              "Lock wait timeout exceeded" (driverCode 1205)
     * The exception may surface either as a QueryException or, when this
     * transaction is nested inside an outer one (e.g. the test suite's
     * RefreshDatabase wrapper), as Laravel's DeadlockException — both extend
     * PDOException, and the detector reads the message, not errorInfo, so it
     * classifies both correctly.
     */
    private function isTransientLockError(PDOException $e): bool
    {
        return app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e);
    }

    /**
     * Decrement the given quantity FEFO across non-expired batches.
     *
     * The last touched batch is recorded as the transaction's primary batch.
     *
     * @throws HttpResponseException
     */
    private function applyOutflow(InventoryItem $item, float $quantity): ?int
    {
        $ledger = (float) $item->ledgerStock();

        if ($quantity > $ledger) {
            abort(422, 'Insufficient stock for this transaction type.');
        }

        $remaining = $quantity;
        $primaryBatchId = null;

        foreach ($item->availableBatches()->lockForUpdate()->get() as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $batch->current_quantity, $remaining);
            $batch->decrement('current_quantity', $take);
            $remaining -= $take;
            $primaryBatchId = $batch->id;
        }

        return $primaryBatchId;
    }

    /**
     * Record incoming stock as a new lot for first-expiry-first-out tracking.
     */
    private function applyInflow(InventoryItem $item, float $quantity): ?int
    {
        $batch = InventoryBatch::create([
            'inventory_item_id' => $item->id,
            'batch_number' => 'B-'.strtoupper(Str::random(8)),
            'expiry_date' => now()->addMonths(24)->toDateString(),
            'initial_quantity' => $quantity,
            'current_quantity' => $quantity,
        ]);

        return $batch->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentItem(InventoryItem $item): array
    {
        return [
            'id' => $item->id,
            'sku' => $item->sku,
            'item_name' => $item->item_name,
            'category' => $item->category,
            'unit_price' => (float) $item->unit_price,
            'stock_quantity' => $item->stock_quantity,
            'reorder_level' => $item->reorder_level,
            'last_restocked' => $item->last_restocked?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTransaction(InventoryTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'item_id' => $transaction->inventory_item_id,
            'item_name' => $transaction->item?->item_name,
            'type' => $transaction->type,
            'quantity' => $transaction->quantity,
            'unit_price' => (float) $transaction->unit_price,
            'total_amount' => (float) $transaction->total_amount,
            'reason' => $transaction->reason,
            'created_at' => $transaction->created_at?->toIso8601String(),
        ];
    }

    /**
     * Get expiring and expired stock alerts with batch details.
     *
     * Returns batches flagged as expiring (within configurable thresholds)
     * or already expired, keyed by alert type. The frontend uses this to
     * surface a priority list to pharmacy staff.
     */
    public function expiryAlerts(): JsonResponse
    {
        $thresholds = Config::get('inventory.expiry_alert_thresholds', [30, 60, 90]);
        $now = now();

        $expiringByThreshold = [];
        foreach ($thresholds as $thresholdDays) {
            $windowEnd = $now->copy()->addDays($thresholdDays);

            $batches = InventoryBatch::whereNotNull('expiry_date')
                ->where('expiry_date', '>=', $now)
                ->where('expiry_date', '<=', $windowEnd)
                ->where('current_quantity', '>', 0)
                ->get();

            foreach ($batches as $batch) {
                $expiringByThreshold[$thresholdDays][] = [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'item_name' => $batch->item->name ?? 'unknown item',
                    'sku' => $batch->item->sku ?? null,
                    'expiry_date' => $batch->expiry_date->toDateString(),
                    'current_quantity' => (float) $batch->current_quantity,
                    'initial_quantity' => (float) $batch->initial_quantity,
                ];
            }
        }

        // Expired batches (expiry date already passed)
        $expiredBatches = InventoryBatch::whereNotNull('expiry_date')
            ->where('expiry_date', '<', $now)
            ->where('current_quantity', '>', 0)
            ->get();

        $expiredList = [];
        foreach ($expiredBatches as $batch) {
            $expiredList[] = [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'item_name' => $batch->item->name ?? 'unknown item',
                'sku' => $batch->item->sku ?? null,
                'expiry_date' => $batch->expiry_date?->toDateString() ?? 'unknown',
                'current_quantity' => (float) $batch->current_quantity,
                'initial_quantity' => (float) $batch->initial_quantity,
            ];
        }

        return response()->json([
            'expiring_by_threshold' => $expiringByThreshold,
            'expired' => $expiredList,
        ]);
    }
}
