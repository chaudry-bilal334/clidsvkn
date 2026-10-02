<?php

namespace App\Models;

use Database\Factories\DispensingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['prescription_id', 'inventory_batch_id', 'quantity_dispensed', 'dispensed_by', 'dispense_group_id'])]
class Dispensing extends Model
{
    /** @use HasFactory<DispensingFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * @return BelongsTo<InventoryBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dispensedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensed_by');
    }

    /**
     * @return BelongsTo<DispenseGroup, $this>
     */
    public function dispenseGroup(): BelongsTo
    {
        return $this->belongsTo(DispenseGroup::class);
    }
}
