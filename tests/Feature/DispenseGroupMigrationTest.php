<?php

use App\Models\Dispensing;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

pest()->use(RefreshDatabase::class);

test('the dispense groups migration down restores the per-line invoice link and up re-applies', function () {
    $pharmacist = User::factory()->create(['role' => 'Pharmacist']);
    $prescription = Prescription::factory()->create(['status' => 'pending']);

    $item = InventoryItem::factory()->create([
        'item_name' => 'Paracetamol 500mg Tablets',
        'unit_price' => 10.00,
        'stock_quantity' => 0,
        'reorder_level' => 0,
    ]);
    $batch = InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-MIG',
        'expiry_date' => now()->addYear()->toDateString(),
        'initial_quantity' => 20,
        'current_quantity' => 20,
    ]);
    $item->stock_quantity = 20;
    $item->save();

    $this->actingAs($pharmacist)
        ->postJson('/api/v1/dispensings', [
            'prescription_id' => $prescription->id,
            'items' => [['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 5]],
        ])
        ->assertCreated();

    // Up state: group table exists, per-line invoice_id is gone.
    expect(Schema::hasTable('dispense_groups'))->toBeTrue()
        ->and(Schema::hasColumn('dispensings', 'dispense_group_id'))->toBeTrue()
        ->and(Schema::hasColumn('dispensings', 'invoice_id'))->toBeFalse();

    $dispensing = Dispensing::query()->first();
    expect($dispensing->dispense_group_id)->not->toBeNull();

    // Roll the migration back. Keep rolling until the group table is gone so
    // the assertion is robust to unrelated migrations that may be created
    // after the dispense-groups batch.
    $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
    $guard = 0;
    while (Schema::hasTable('dispense_groups') && $guard < 20) {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $guard++;
    }

    // Down state: per-line invoice_id restored from the group, group table gone.
    expect(Schema::hasTable('dispense_groups'))->toBeFalse()
        ->and(Schema::hasColumn('dispensings', 'invoice_id'))->toBeTrue()
        ->and(Schema::hasColumn('dispensings', 'dispense_group_id'))->toBeFalse();

    $dispensing = Dispensing::query()->first();
    expect($dispensing->invoice_id)->not->toBeNull();

    // Re-apply.
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('dispense_groups'))->toBeTrue()
        ->and(Schema::hasColumn('dispensings', 'invoice_id'))->toBeFalse();

    $dispensing = Dispensing::query()->first();
    expect($dispensing->dispense_group_id)->not->toBeNull();
});
