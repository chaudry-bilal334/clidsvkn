<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function receivePurchase(Purchase $purchase, User $actor): Purchase
    {
        return DB::transaction(function () use ($purchase, $actor): Purchase {
            $purchase->loadMissing('items.inventoryItem');

            foreach ($purchase->items as $item) {
                $this->receiveItem($item, $actor);
            }

            $purchase->status = 'received';
            $purchase->received_by = $actor->id;
            $purchase->received_at = now();
            $purchase->save();

            return $purchase;
        });
    }

    private function receiveItem(PurchaseItem $item, User $actor): void
    {
        $inventoryItem = $item->inventoryItem;

        if (! $inventoryItem) {
            $inventoryItem = $this->createInventoryItem($item);
        }

        $batch = InventoryBatch::create([
            'inventory_item_id' => $inventoryItem->id,
            'batch_number' => $item->batch_number ?: 'BATCH-'.strtoupper(str()->random(8)),
            'expiry_date' => $item->expiry_date ?? now()->addYears(2),
            'initial_quantity' => $item->quantity,
            'current_quantity' => $item->quantity,
        ]);

        InventoryTransaction::create([
            'inventory_item_id' => $inventoryItem->id,
            'inventory_batch_id' => $batch->id,
            'type' => 'in',
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'total_amount' => $item->line_total,
            'reason' => 'Purchase received: '.$item->purchase->purchase_number,
        ]);

        $inventoryItem->stock_quantity = $inventoryItem->ledgerStock();
        $inventoryItem->last_restocked = now();
        $inventoryItem->save();
    }

    private function createInventoryItem(PurchaseItem $item): InventoryItem
    {
        return InventoryItem::create([
            'branch_id' => $item->purchase->branch_id,
            'sku' => $item->item_sku ?? 'SKU-'.strtoupper(str()->random(8)),
            'item_name' => $item->item_name,
            'category' => 'medicine',
            'unit_price' => $item->unit_price,
            'stock_quantity' => 0,
            'reorder_level' => 10,
        ]);
    }
}
