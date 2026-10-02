<?php

use App\Models\AuditLog;
use App\Models\DispenseGroup;
use App\Models\Dispensing;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Prescription;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function di_pharmacist(): User
{
    return User::factory()->create(['role' => 'Pharmacist']);
}

function di_accountant(): User
{
    return User::factory()->create(['role' => 'Accountant']);
}

function di_prescription(): Prescription
{
    return Prescription::factory()->create(['status' => 'pending']);
}

/**
 * An inventory item with a known unit price and a single non-expired batch.
 *
 * @return array{0: InventoryItem, 1: InventoryBatch}
 */
function di_item_with_batch(float $unitPrice, int $quantity, string $name = 'Paracetamol 500mg Tablets'): array
{
    // Create the item with zero stock so the factory's auto-created batch is
    // empty (and excluded from FEFO), leaving exactly one batch with the
    // quantity under test.
    $item = InventoryItem::factory()->create([
        'item_name' => $name,
        'unit_price' => $unitPrice,
        'stock_quantity' => 0,
        'reorder_level' => 0,
    ]);

    $batch = InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-TEST',
        'expiry_date' => now()->addYear()->toDateString(),
        'initial_quantity' => $quantity,
        'current_quantity' => $quantity,
    ]);

    $item->stock_quantity = $quantity;
    $item->save();

    return [$item, $batch];
}

function di_dispense($test, User $actor, Prescription $prescription, array $items)
{
    return $test->actingAs($actor)
        ->postJson('/api/v1/dispensings', [
            'prescription_id' => $prescription->id,
            'items' => $items,
        ]);
}

function di_invoice_for(Dispensing $dispensing): ?Invoice
{
    return $dispensing->dispenseGroup?->invoice;
}

test('a multi-medicine dispense raises exactly one invoice with one line per medicine', function () {
    [$itemA] = di_item_with_batch(10.00, 20, 'Paracetamol 500mg Tablets');
    [$itemB] = di_item_with_batch(25.00, 20, 'Amoxicillin 500mg');
    $prescription = di_prescription();
    $pharmacist = di_pharmacist();

    di_dispense($this, $pharmacist, $prescription, [
        ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 4],
        ['medicine_name' => 'Amoxicillin 500mg', 'quantity' => 2],
    ])->assertCreated();

    $group = DispenseGroup::query()->first();
    $invoice = $group->invoice;

    expect(DispenseGroup::count())->toBe(1)
        ->and(Invoice::count())->toBe(1)
        ->and(Dispensing::count())->toBe(2)
        ->and($invoice)->not->toBeNull()
        ->and((float) $invoice->total)->toBe(90.0)
        ->and($invoice->items()->count())->toBe(2);

    $lines = $invoice->items()->get();
    expect((float) $lines[0]->line_total)->toBe(40.0)
        ->and((float) $lines[1]->line_total)->toBe(50.0);
});

test('one medicine across two FEFO batches raises a single invoice line', function () {
    $item = InventoryItem::factory()->create([
        'item_name' => 'Paracetamol 500mg Tablets',
        'unit_price' => 10.00,
        'stock_quantity' => 0,
        'reorder_level' => 0,
    ]);

    $older = InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-OLDER',
        'expiry_date' => now()->addMonths(6)->toDateString(),
        'initial_quantity' => 10,
        'current_quantity' => 10,
    ]);
    $newer = InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-NEWER',
        'expiry_date' => now()->addMonths(18)->toDateString(),
        'initial_quantity' => 10,
        'current_quantity' => 10,
    ]);
    $item->stock_quantity = 20;
    $item->save();

    $prescription = di_prescription();
    $pharmacist = di_pharmacist();

    di_dispense($this, $pharmacist, $prescription, [
        ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 15],
    ])->assertCreated();

    // Two dispensing rows (one per batch) but ONE invoice with ONE line.
    expect(Dispensing::count())->toBe(2)
        ->and(Invoice::count())->toBe(1)
        ->and(InvoiceItem::count())->toBe(1);

    $invoice = DispenseGroup::query()->first()->invoice;
    $line = $invoice->items()->first();

    expect((float) $line->quantity)->toBe(15.0)
        ->and((float) $line->unit_price)->toBe(10.0)
        ->and((float) $line->line_total)->toBe(150.0)
        ->and((float) $invoice->total)->toBe(150.0);
});

test('dispensing creates exactly one invoice with server-derived totals', function () {
    [$item, $batch] = di_item_with_batch(10.00, 20);
    $prescription = di_prescription();
    $pharmacist = di_pharmacist();

    di_dispense($this, $pharmacist, $prescription, [
        ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 4],
    ])
        ->assertCreated()
        ->assertJsonPath('data.invoice.invoice_number', fn ($number) => str_starts_with($number, 'INV-'));

    $dispensing = Dispensing::query()->first();
    $invoice = di_invoice_for($dispensing);

    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->subtotal)->toBe(40.0)
        ->and((float) $invoice->tax)->toBe(0.0)
        ->and((float) $invoice->total)->toBe(40.0)
        ->and($invoice->status)->toBe(Invoice::STATUS_GENERATED)
        ->and((float) $invoice->balance_due)->toBe(40.0)
        ->and($dispensing->dispenseGroup->invoice_id)->toBe($invoice->id);

    $line = $invoice->items()->first();
    expect($line->description)->toBe('Paracetamol 500mg Tablets')
        ->and((float) $line->quantity)->toBe(4.0)
        ->and((float) $line->unit_price)->toBe(10.0)
        ->and((float) $line->line_total)->toBe(40.0);

    expect(AuditLog::query()->where('action', 'invoice.created')->count())->toBe(1);
});

test('client-supplied price total and status fields are ignored', function () {
    [$item, $batch] = di_item_with_batch(10.00, 20);
    $prescription = di_prescription();
    $pharmacist = di_pharmacist();

    $this->actingAs($pharmacist)
        ->postJson('/api/v1/dispensings', [
            'prescription_id' => $prescription->id,
            'items' => [
                ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 3],
            ],
            'price' => 999.99,
            'total' => 1.00,
            'status' => 'paid',
            'unit_price' => 0.01,
        ])
        ->assertCreated();

    $invoice = di_invoice_for(Dispensing::query()->first());

    expect((float) $invoice->total)->toBe(30.0)
        ->and($invoice->status)->toBe(Invoice::STATUS_GENERATED)
        ->and((float) $invoice->items()->first()->unit_price)->toBe(10.0);
});

test('a double-submitted dispense yields one invoice and one stock deduction', function () {
    [$item, $batch] = di_item_with_batch(10.00, 20);
    $prescription = di_prescription();
    $pharmacist = di_pharmacist();

    di_dispense($this, $pharmacist, $prescription, [
        ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 5],
    ])->assertCreated();

    // The prescription is now dispensed, so a retry is rejected before it can
    // touch stock or billing again.
    di_dispense($this, $pharmacist, $prescription, [
        ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 5],
    ])->assertUnprocessable();

    expect(Dispensing::count())->toBe(1)
        ->and(DispenseGroup::count())->toBe(1)
        ->and(Invoice::count())->toBe(1)
        ->and(InventoryTransaction::query()->where('type', 'dispense')->count())->toBe(1)
        ->and((float) $item->fresh()->stock_quantity)->toBe(15.0);
});

test('the database enforces at most one invoice per dispense group', function () {
    [$item, $batch] = di_item_with_batch(10.00, 20);
    $prescription = di_prescription();
    $pharmacist = di_pharmacist();

    di_dispense($this, $pharmacist, $prescription, [
        ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 2],
    ])->assertCreated();

    $group = DispenseGroup::query()->first();
    $invoice = $group->invoice;

    // A second group pointing at the same invoice violates the unique link.
    DispenseGroup::create([
        'prescription_id' => $prescription->id,
        'invoice_id' => $invoice->id,
        'created_by' => $pharmacist->id,
    ]);
})->throws(UniqueConstraintViolationException::class);

test('an invoice creation failure rolls the dispense and stock back', function () {
    $billing = $this->mock(BillingService::class);
    $billing->shouldReceive('createDispenseInvoice')
        ->andThrow(new RuntimeException('billing unavailable'));

    [$item, $batch] = di_item_with_batch(10.00, 20);
    $prescription = di_prescription();
    $pharmacist = di_pharmacist();

    $this->actingAs($pharmacist)
        ->postJson('/api/v1/dispensings', [
            'prescription_id' => $prescription->id,
            'items' => [
                ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 5],
            ],
        ])
        ->assertServerError();

    expect(Dispensing::count())->toBe(0)
        ->and(DispenseGroup::count())->toBe(0)
        ->and(Invoice::count())->toBe(0)
        ->and(InventoryTransaction::query()->where('type', 'dispense')->count())->toBe(0)
        ->and((float) $item->fresh()->stock_quantity)->toBe(20.0)
        ->and((float) $batch->fresh()->current_quantity)->toBe(20.0);
});

test('voiding a group returns all stock and voids the single invoice', function () {
    [$item, $batch] = di_item_with_batch(10.00, 20);
    $prescription = di_prescription();
    $pharmacist = di_pharmacist();

    di_dispense($this, $pharmacist, $prescription, [
        ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 5],
    ])->assertCreated();

    $dispensing = Dispensing::query()->first();
    $invoice = di_invoice_for($dispensing);

    $this->actingAs($pharmacist)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/dispensings/{$dispensing->id}/void", ['reason' => 'Patient cancelled'])
        ->assertOk();

    expect((float) $item->fresh()->stock_quantity)->toBe(20.0)
        ->and((float) $batch->fresh()->current_quantity)->toBe(20.0)
        ->and($invoice->fresh()->status)->toBe(Invoice::STATUS_VOIDED)
        ->and($dispensing->fresh()->voided_at)->not->toBeNull()
        ->and(InventoryTransaction::query()->where('type', 'return')->count())->toBe(1);

    expect(AuditLog::query()->where('action', 'dispensing.voided')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'invoice.voided')->count())->toBe(1);
});

test('voiding one line of a multi-line group voids the whole group', function () {
    di_item_with_batch(10.00, 20, 'Paracetamol 500mg Tablets');
    di_item_with_batch(25.00, 20, 'Amoxicillin 500mg');
    $prescription = di_prescription();
    $pharmacist = di_pharmacist();

    di_dispense($this, $pharmacist, $prescription, [
        ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 4],
        ['medicine_name' => 'Amoxicillin 500mg', 'quantity' => 2],
    ])->assertCreated();

    $first = Dispensing::query()->orderBy('id')->first();
    $second = Dispensing::query()->orderBy('id')->skip(1)->first();
    $invoice = di_invoice_for($first);

    // Voiding the first line voids the whole group (both lines).
    $this->actingAs($pharmacist)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/dispensings/{$first->id}/void", ['reason' => 'Cancel whole dispense'])
        ->assertOk();

    expect($first->fresh()->voided_at)->not->toBeNull()
        ->and($second->fresh()->voided_at)->not->toBeNull()
        ->and($invoice->fresh()->status)->toBe(Invoice::STATUS_VOIDED)
        ->and((float) $invoice->fresh()->total)->toBe(90.0);
});

test('voiding a dispense whose invoice has payments is blocked with 422 and changes nothing', function () {
    [$item, $batch] = di_item_with_batch(10.00, 20);
    $prescription = di_prescription();
    $pharmacist = di_pharmacist();

    di_dispense($this, $pharmacist, $prescription, [
        ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 5],
    ])->assertCreated();

    $dispensing = Dispensing::query()->first();
    $invoice = di_invoice_for($dispensing);

    // Settle the invoice in full (payments require billing.manage).
    $this->actingAs(di_accountant())
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => 50.00, 'method' => 'cash'])
        ->assertOk();

    expect((float) $invoice->fresh()->amount_paid)->toBe(50.0);

    $this->actingAs($pharmacist)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/dispensings/{$dispensing->id}/void", ['reason' => 'Attempted void'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.dispensing.0', 'This dispense has a paid invoice and cannot be voided. Issue a refund or reversal first.');

    // Nothing changed: stock stays deducted, invoice stays paid.
    expect((float) $item->fresh()->stock_quantity)->toBe(15.0)
        ->and((float) $invoice->fresh()->amount_paid)->toBe(50.0)
        ->and($invoice->fresh()->status)->toBe(Invoice::STATUS_PAID)
        ->and(InventoryTransaction::query()->where('type', 'return')->count())->toBe(0);
});

test('a full prescription to dispense to stock ledger to invoice to payment flow', function () {
    [$item, $batch] = di_item_with_batch(10.00, 20);
    $prescription = di_prescription();
    $pharmacist = di_pharmacist();

    di_dispense($this, $pharmacist, $prescription, [
        ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 5],
    ])->assertCreated();

    $dispensing = Dispensing::query()->first();
    $invoice = di_invoice_for($dispensing);

    // Stock ledger reflects the deduction.
    $movement = InventoryTransaction::query()->where('type', 'dispense')->first();
    expect((float) $movement->quantity)->toBe(5.0)
        ->and((float) $movement->unit_price)->toBe(10.0)
        ->and((float) $movement->total_amount)->toBe(50.0)
        ->and((float) $item->fresh()->stock_quantity)->toBe(15.0);

    // Settle the invoice (payments require billing.manage).
    $this->actingAs(di_accountant())
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => 50.00, 'method' => 'cash'])
        ->assertOk()
        ->assertJsonPath('data.status', Invoice::STATUS_PAID)
        ->assertJsonPath('data.balance_due', 0);

    expect((float) $invoice->fresh()->amount_paid)->toBe(50.0)
        ->and((float) $invoice->fresh()->balance_due)->toBe(0.0);
});

test('invoice lines reconcile with stock movements valued at the billed price', function () {
    [$item, $batch] = di_item_with_batch(10.00, 20);
    $prescription = di_prescription();
    $pharmacist = di_pharmacist();

    di_dispense($this, $pharmacist, $prescription, [
        ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 6],
    ])->assertCreated();

    $invoiceLinesTotal = (float) InvoiceItem::query()->sum('line_total');
    $stockMovementsTotal = (float) InventoryTransaction::query()
        ->where('type', 'dispense')
        ->sum('total_amount');

    expect($invoiceLinesTotal)->toBe(60.0)
        ->and($stockMovementsTotal)->toBe(60.0)
        ->and($invoiceLinesTotal)->toBe($stockMovementsTotal);
});
