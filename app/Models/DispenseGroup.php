<?php

namespace App\Models;

use Database\Factories\DispenseGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['prescription_id', 'invoice_id', 'created_by'])]
class DispenseGroup extends Model
{
    /** @use HasFactory<DispenseGroupFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return HasMany<Dispensing, $this>
     */
    public function dispensings(): HasMany
    {
        return $this->hasMany(Dispensing::class);
    }
}
