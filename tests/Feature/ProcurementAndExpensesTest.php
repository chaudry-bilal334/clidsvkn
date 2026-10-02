<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function procurement_pharmacist(): User
{
    return User::factory()->create(['role' => 'Pharmacist']);
}

function procurement_accountant(): User
{
    return User::factory()->create(['role' => 'Accountant']);
}

function procurement_forbidden_user(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

/**
 * Build a valid purchase-order request payload.
 */
function procurement_purchase_payload(array $overrides = [], ?array $items = null): array
{
    return [
        'supplier_id' => Supplier::factory()->create()->id,
        'purchase_date' => now()->format('Y-m-d'),
        'items' => $items ?? [[
            'item_name' => 'Paracetamol 500mg Tablets',
            'quantity' => 10,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]],
        ...$overrides,
    ];
}

// ---------------------------------------------------------------------------
// Suppliers
// ---------------------------------------------------------------------------

test('a pharmacist can create, list, update and delete suppliers', function () {
    $user = procurement_pharmacist();

    $this->actingAs($user)
        ->postJson('/api/v1/suppliers', [
            'name' => 'Global Medics Ltd',
            'contact_person' => 'John Doe',
            'email' => 'sales@globalmedics.test',
            'phone' => '+1 (555) 000-1111',
            'tax_number' => 'NTN-1234567',
            'is_active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Global Medics Ltd');

    $this->actingAs($user)
        ->getJson('/api/v1/suppliers')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $supplier = Supplier::firstOrFail();

    $this->actingAs($user)
        ->putJson("/api/v1/suppliers/{$supplier->id}", ['name' => 'Global Medics Plus'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Global Medics Plus');

    $this->actingAs($user)
        ->deleteJson("/api/v1/suppliers/{$supplier->id}")
        ->assertOk();

    expect(Supplier::find($supplier->id))->toBeNull();
});

test('supplier endpoints are forbidden for roles without procurement access', function () {
    $user = procurement_forbidden_user();

    $this->actingAs($user)->getJson('/api/v1/suppliers')->assertForbidden();
    $this->actingAs($user)->postJson('/api/v1/suppliers', ['name' => 'Should Not Save'])->assertForbidden();
});

// ---------------------------------------------------------------------------
// Purchases
// ---------------------------------------------------------------------------

test('a pharmacist can create a purchase with items and totals are calculated', function () {
    $user = procurement_pharmacist();

    $this->actingAs($user)
        ->postJson('/api/v1/purchases', procurement_purchase_payload([], [
            [
                'item_name' => 'Paracetamol 500mg Tablets',
                'quantity' => 10,
                'unit_price' => 100,
                'tax_rate' => 10,
            ], [
                'item_name' => 'Saline Solution 500ml',
                'quantity' => 5,
                'unit_price' => 40,
                'tax_rate' => 0,
            ],
        ]))
        ->assertCreated();

    $purchase = Purchase::firstOrFail();
    expect($purchase->items)->toHaveCount(2);
    expect($purchase->subtotal)->toBe('1200.00');
    expect($purchase->tax)->toBe('100.00');
    expect($purchase->total)->toBe('1300.00');
    expect($purchase->purchase_number)->toMatch('/^PO-\d{6}$/');
});

test('purchase creation requires a supplier and at least one line item', function () {
    $user = procurement_pharmacist();

    $this->actingAs($user)
        ->postJson('/api/v1/purchases', [
            'purchase_date' => now()->format('Y-m-d'),
            'items' => [[
                'item_name' => 'Paracetamol 500mg',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->postJson('/api/v1/purchases', [
            'supplier_id' => Supplier::factory()->create()->id,
            'purchase_date' => now()->format('Y-m-d'),
            'items' => [],
        ])
        ->assertUnprocessable();
});

test('only draft purchases can be updated or deleted', function () {
    $user = procurement_pharmacist();
    $supplier = Supplier::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/purchases', procurement_purchase_payload())->assertCreated();
    $purchase = Purchase::firstOrFail();

    $this->actingAs($user)
        ->putJson("/api/v1/purchases/{$purchase->id}", [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'notes' => 'Updated before order placement',
        ])
        ->assertOk()
        ->assertJsonPath('data.notes', 'Updated before order placement');

    $this->actingAs($user)
        ->postJson("/api/v1/purchases/{$purchase->id}/receive")
        ->assertOk();

    $this->actingAs($user)
        ->putJson("/api/v1/purchases/{$purchase->id}", ['notes' => 'Should be rejected'])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->deleteJson("/api/v1/purchases/{$purchase->id}")
        ->assertUnprocessable();
});

test('receiving a purchase creates a batch, records an inbound transaction and increases stock', function () {
    $user = procurement_pharmacist();
    $item = InventoryItem::factory()->create(['stock_quantity' => 0, 'reorder_level' => 5]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/purchases', [
            'supplier_id' => Supplier::factory()->create()->id,
            'purchase_date' => now()->format('Y-m-d'),
            'items' => [[
                'inventory_item_id' => $item->id,
                'item_name' => 'Paracetamol 500mg Tablets',
                'item_sku' => $item->sku,
                'quantity' => 10,
                'unit_price' => 75,
                'tax_rate' => 0,
                'batch_number' => 'BATCH-A1',
                'expiry_date' => now()->addYears(2)->format('Y-m-d'),
            ]],
        ])
        ->assertCreated();

    $purchase = Purchase::firstOrFail();

    $this->actingAs($user)
        ->postJson("/api/v1/purchases/{$purchase->id}/receive")
        ->assertOk()
        ->assertJsonPath('data.status', 'received');

    expect($item->fresh()->stock_quantity)->toBe(10);
    expect($item->fresh()->last_restocked)->not->toBeNull();

    $batch = InventoryBatch::where('batch_number', 'BATCH-A1')->firstOrFail();
    expect($batch->inventory_item_id)->toBe($item->id);
    expect($batch->current_quantity)->toBe('10.00');
    expect($batch->batch_number)->toBe('BATCH-A1');

    $transaction = InventoryTransaction::firstOrFail();
    expect($transaction->type)->toBe('in');
    expect($transaction->quantity)->toBe(10);
    expect($transaction->unit_price)->toBe('75.00');
    expect($transaction->total_amount)->toBe('750.00');
    expect($transaction->inventory_batch_id)->toBe($batch->id);
    expect($transaction->reason)->toContain($purchase->purchase_number);

    // The factory's afterCreating hook echoes the item's stock into a ledger
    // batch, so the received batch is the second one.
    expect(InventoryBatch::query()->count())->toBe(2);
});

test('receiving a purchase derives stock_quantity from the transaction ledger', function () {
    $user = procurement_pharmacist();
    $item = InventoryItem::factory()->create(['stock_quantity' => 0, 'reorder_level' => 5]);

    $this->actingAs($user)
        ->postJson('/api/v1/purchases', [
            'supplier_id' => Supplier::factory()->create()->id,
            'purchase_date' => now()->format('Y-m-d'),
            'items' => [[
                'inventory_item_id' => $item->id,
                'item_name' => 'Paracetamol 500mg Tablets',
                'item_sku' => $item->sku,
                'quantity' => 10,
                'unit_price' => 75,
                'tax_rate' => 0,
                'batch_number' => 'BATCH-LEDGER-1',
                'expiry_date' => now()->addYears(2)->format('Y-m-d'),
            ]],
        ])
        ->assertCreated();

    $purchase = Purchase::firstOrFail();

    $this->actingAs($user)
        ->postJson("/api/v1/purchases/{$purchase->id}/receive")
        ->assertOk();

    $item->refresh();

    $ledgerSum = (float) InventoryTransaction::query()
        ->where('inventory_item_id', $item->id)
        ->sum('quantity');

    expect($item->stock_quantity)->toBe(10);
    expect((float) $item->stock_quantity)->toBe($ledgerSum);
    expect($item->stock_quantity)->toBe((int) round($item->ledgerStock()));
});

test('a purchase cannot be received twice and a cancelled purchase cannot be received', function () {
    $user = procurement_pharmacist();

    $this->actingAs($user)->postJson('/api/v1/purchases', procurement_purchase_payload())->assertCreated();
    $purchase = Purchase::firstOrFail();

    $this->actingAs($user)->postJson("/api/v1/purchases/{$purchase->id}/receive")->assertOk();
    $this->actingAs($user)->postJson("/api/v1/purchases/{$purchase->id}/receive")->assertUnprocessable();

    $this->actingAs($user)
        ->postJson('/api/v1/purchases', [
            'supplier_id' => Supplier::factory()->create()->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'cancelled',
            'items' => [[
                'item_name' => 'Bandage Roll',
                'quantity' => 2,
                'unit_price' => 25,
            ]],
        ])
        ->assertCreated();

    $cancelled = Purchase::where('status', 'cancelled')->firstOrFail();
    $this->actingAs($user)
        ->postJson("/api/v1/purchases/{$cancelled->id}/receive")
        ->assertUnprocessable();
});

test('purchase endpoints are forbidden for roles without procurement access', function () {
    $user = procurement_forbidden_user();

    $this->actingAs($user)->getJson('/api/v1/purchases')->assertForbidden();
    $this->actingAs($user)->postJson('/api/v1/purchases', [
        'supplier_id' => Supplier::factory()->create()->id,
        'purchase_date' => now()->format('Y-m-d'),
        'items' => [['item_name' => 'X', 'quantity' => 1, 'unit_price' => 10]],
    ])->assertForbidden();
});

// ---------------------------------------------------------------------------
// Expense categories
// ---------------------------------------------------------------------------

test('an accountant can create, list and rename expense categories', function () {
    $user = procurement_accountant();

    $this->actingAs($user)
        ->postJson('/api/v1/expense-categories', [
            'name' => 'Utilities',
            'description' => 'Electricity, water, gas',
            'sort_order' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Utilities');

    $this->actingAs($user)
        ->getJson('/api/v1/expense-categories')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $category = ExpenseCategory::firstOrFail();

    $this->actingAs($user)
        ->putJson("/api/v1/expense-categories/{$category->id}", ['name' => 'Utility Bills'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Utility Bills');
});

test('an expense category with existing expenses cannot be deleted', function () {
    $user = procurement_accountant();
    $category = ExpenseCategory::factory()->create();
    Expense::factory()->create(['expense_category_id' => $category->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/expense-categories/{$category->id}")
        ->assertUnprocessable();

    expect(ExpenseCategory::find($category->id))->not->toBeNull();
});

test('expense category endpoints are gated by role', function () {
    $user = procurement_forbidden_user();

    $this->actingAs($user)->getJson('/api/v1/expense-categories')->assertForbidden();
    $this->actingAs($user)->postJson('/api/v1/expense-categories', ['name' => 'X'])->assertForbidden();
});

// ---------------------------------------------------------------------------
// Expenses
// ---------------------------------------------------------------------------

test('an accountant can record an expense and the total is computed', function () {
    $user = procurement_accountant();
    $category = ExpenseCategory::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/expenses', [
            'expense_category_id' => $category->id,
            'expense_date' => now()->format('Y-m-d'),
            'vendor' => 'IESCO',
            'amount' => 100.50,
            'tax' => 5.00,
            'description' => 'Electricity bill',
        ])
        ->assertCreated()
        ->assertJsonPath('data.amount', '100.50')
        ->assertJsonPath('data.total', '105.50')
        ->assertJsonPath('data.payment_status', 'pending');

    $expense = Expense::firstOrFail();
    expect($expense->expense_number)->toMatch('/^EXP-\d{6}$/');
    expect($expense->created_by)->toBe($user->id);
});

test('a pending expense can be approved by an accountant and becomes immutable', function () {
    $user = procurement_accountant();
    $category = ExpenseCategory::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/expenses', [
            'expense_category_id' => $category->id,
            'expense_date' => now()->format('Y-m-d'),
            'amount' => 5000,
            'description' => 'Rent',
        ])
        ->assertCreated();

    $expense = Expense::firstOrFail();

    $this->actingAs($user)
        ->postJson("/api/v1/expenses/{$expense->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.payment_status', 'paid');

    $expense->refresh();
    expect($expense->approved_by)->toBe($user->id);
    expect($expense->approved_at)->not->toBeNull();

    $this->actingAs($user)
        ->putJson("/api/v1/expenses/{$expense->id}", ['amount' => 1])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->postJson("/api/v1/expenses/{$expense->id}/approve")
        ->assertUnprocessable();

    $this->actingAs($user)
        ->deleteJson("/api/v1/expenses/{$expense->id}")
        ->assertUnprocessable();
});

test('a pending expense can be rejected with a reason and cannot be approved afterward', function () {
    $user = procurement_accountant();
    $category = ExpenseCategory::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/expenses', [
            'expense_category_id' => $category->id,
            'expense_date' => now()->format('Y-m-d'),
            'amount' => 5000,
            'description' => 'Rent',
        ])
        ->assertCreated();

    $expense = Expense::firstOrFail();

    $this->actingAs($user)
        ->postJson("/api/v1/expenses/{$expense->id}/reject", [
            'reason' => 'Missing receipt',
        ])
        ->assertOk()
        ->assertJsonPath('data.payment_status', 'rejected');

    $expense->refresh();
    expect($expense->payment_status)->toBe('rejected')
        ->and($expense->approved_by)->toBe($user->id)
        ->and($expense->notes)->toContain('Missing receipt');

    $this->actingAs($user)
        ->postJson("/api/v1/expenses/{$expense->id}/approve")
        ->assertUnprocessable();
});

test('expense creation requires a valid category', function () {
    $user = procurement_accountant();

    $this->actingAs($user)
        ->postJson('/api/v1/expenses', [
            'expense_date' => now()->format('Y-m-d'),
            'amount' => 100,
        ])
        ->assertUnprocessable();
});

test('expense endpoints are gated by role', function () {
    $user = procurement_forbidden_user();

    $this->actingAs($user)->getJson('/api/v1/expenses')->assertForbidden();
    $this->actingAs($user)->postJson('/api/v1/expenses', ['amount' => 100])->assertForbidden();
});
