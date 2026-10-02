<?php

use App\Models\Dispensing;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Real-parallel race coverage for the last-unit dispense invariant: one
 * concurrent dispense wins, the loser gets no invoice, and stock never drops
 * below zero.
 *
 * A file-backed SQLite database is required because forked processes cannot
 * share an in-memory store (same pattern as ConcurrencyRaceTest). This file
 * deliberately does NOT use RefreshDatabase.
 */
beforeEach(function (): void {
    $this->raceDbFile = tempnam(sys_get_temp_dir(), 'dir_race_').'.sqlite';

    config(['database.connections.sqlite.database' => $this->raceDbFile]);
    DB::purge('sqlite');

    $this->artisan('migrate', ['--force' => true]);
});

afterEach(function (): void {
    DB::purge('sqlite');

    if (file_exists($this->raceDbFile)) {
        @unlink($this->raceDbFile);
    }
});

test('a concurrent last-unit dispense race yields exactly one invoice and never negative stock', function () {
    $pharmacist = User::factory()->create(['role' => 'Pharmacist']);
    $prescription = Prescription::factory()->create(['status' => 'pending']);

    $item = InventoryItem::factory()->create([
        'item_name' => 'Amoxicillin 500mg',
        'unit_price' => 10.00,
        'stock_quantity' => 0,
        'reorder_level' => 0,
    ]);

    $batch = InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-RACE',
        'expiry_date' => now()->addYear()->toDateString(),
        'initial_quantity' => 1,
        'current_quantity' => 1,
    ]);

    $item->stock_quantity = 1;
    $item->save();

    $startFlag = tempnam(sys_get_temp_dir(), 'dir_flag_');

    $outcomes = race_fork(2, function () use ($pharmacist, $prescription) {
        return (string) $this->actingAs($pharmacist)
            ->postJson('/api/v1/dispensings', [
                'prescription_id' => $prescription->id,
                'items' => [
                    ['medicine_name' => 'Amoxicillin 500mg', 'quantity' => 1],
                ],
            ])
            ->status();
    }, $startFlag);

    $successes = collect($outcomes)->filter(fn (string $outcome): bool => $outcome === '201');

    // Exactly one dispense wins; the loser lands on a handled 4xx, never 500.
    expect($successes)->toHaveCount(1)
        ->and(collect($outcomes)->filter(fn (string $outcome): bool => str_starts_with($outcome, '500'))->count())->toBe(0)
        ->and(Dispensing::count())->toBe(1)
        ->and(Invoice::count())->toBe(1)
        ->and((float) $item->fresh()->stock_quantity)->toBe(0.0)
        ->and((float) $batch->fresh()->current_quantity)->toBe(0.0);
});
