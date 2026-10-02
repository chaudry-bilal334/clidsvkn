<?php

namespace App\Models;

use App\Models\Concerns\BranchScope;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_id', 'sku', 'item_name', 'category', 'unit_price', 'stock_quantity', 'reorder_level', 'last_restocked'])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use BranchScope, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'last_restocked' => 'date',
        ];
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity <= $this->reorder_level;
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<InventoryTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'inventory_item_id');
    }

    /**
     * @return HasMany<InventoryBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class, 'inventory_item_id');
    }

    /**
     * Non-expired, non-deleted batches ordered by expiry for FEFO dispensing.
     *
     * @return HasMany<InventoryBatch, $this>
     */
    public function availableBatches(): HasMany
    {
        return $this->batches()
            ->where('expiry_date', '>', today()->toDateString())
            ->where('current_quantity', '>', 0)
            ->orderBy('expiry_date')
            ->orderBy('id');
    }

    /**
     * Total quantity available across non-expired batches (the ledger truth).
     */
    public function ledgerStock(): float
    {
        return (float) $this->availableBatches()->sum('current_quantity');
    }
}
