<?php

namespace App\Models;

use Database\Factories\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['invoice_id', 'description', 'quantity', 'unit_price', 'line_total'])]
class InvoiceItem extends Model
{
    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Defense in depth: line items on a finalized invoice must never be edited
     * or deleted. The reversal path creates new Payment rows and never touches
     * InvoiceItem, so this guard does not interfere with legitimate corrections.
     */
    protected static function booted(): void
    {
        static::updating(function (InvoiceItem $item): void {
            if ($item->invoice?->isFinalized()) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['A finalized invoice line item cannot be edited.'],
                ]);
            }
        });

        static::deleting(function (InvoiceItem $item): void {
            if ($item->invoice?->isFinalized()) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['A finalized invoice line item cannot be deleted.'],
                ]);
            }
        });
    }
}
