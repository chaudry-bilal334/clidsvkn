<?php

use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\StockAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

/**
 * An inventory with three batches: one expiring in 15 days (within the 30-day
 * window), one expiring in 40 days (only within the 60+ windows), and one
 * already expired.
 *
 * @return array{0: InventoryItem}
 */
function expiry_item(): array
{
    $item = InventoryItem::factory()->create([
        'item_name' => 'Expiry Test Item',
        'unit_price' => 10,
        'stock_quantity' => 0,
        'reorder_level' => 0,
    ]);

    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-15D',
        'expiry_date' => now()->addDays(15)->toDateString(),
        'initial_quantity' => 100,
        'current_quantity' => 100,
    ]);

    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-40D',
        'expiry_date' => now()->addDays(40)->toDateString(),
        'initial_quantity' => 50,
        'current_quantity' => 50,
    ]);

    InventoryBatch::factory()->expired()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-EXPIRED',
        'expiry_date' => now()->subDays(5)->toDateString(),
        'initial_quantity' => 80,
        'current_quantity' => 80,
    ]);

    return [$item];
}

it('flags batches expiring within each threshold and expired batches', function () {
    [$item] = expiry_item();

    $this->artisan('clinic:scan-expiring-stock')
        ->assertExitCode(0);

    $this->assertDatabaseHas('stock_alerts', [
        'alert_type' => 'expiring',
        'threshold_days' => 30,
    ]);
    $this->assertDatabaseHas('stock_alerts', [
        'alert_type' => 'expiring',
        'threshold_days' => 60,
    ]);
    $this->assertDatabaseHas('stock_alerts', [
        'alert_type' => 'expiring',
        'threshold_days' => 90,
    ]);
    $this->assertDatabaseHas('stock_alerts', [
        'alert_type' => 'expired',
        'threshold_days' => 0,
    ]);

    expect(StockAlert::count())->toBe(6);
});

it('does not re-alert a batch that already has an alert for the same threshold', function () {
    [$item] = expiry_item();

    $this->artisan('clinic:scan-expiring-stock');
    $this->artisan('clinic:scan-expiring-stock');

    expect(StockAlert::count())->toBe(6);
});

it('sends expiry notifications to pharmacy staff', function () {
    User::factory()->create(['role' => 'Pharmacist', 'phone' => '+923001111111', 'notification_preferences' => ['sms' => true]]);
    User::factory()->create(['role' => 'Admin', 'phone' => '+923002222222', 'notification_preferences' => ['sms' => true]]);

    [$item] = expiry_item();

    $this->artisan('clinic:scan-expiring-stock')
        ->assertExitCode(0);

    // The drivers are dormant/log-only in the test env, so notifications
    // fall back to notification_logs rows with status "logged".
    $this->assertDatabaseHas('notification_logs', [
        'channel' => 'sms',
        'status' => 'logged',
    ]);
});

it('returns expiring and expired batches from the expiry-alerts endpoint', function () {
    [$item] = expiry_item();

    $this->artisan('clinic:scan-expiring-stock');

    $pharmacist = User::factory()->create(['role' => 'Pharmacist']);

    $response = $this->actingAs($pharmacist)
        ->getJson('/api/v1/inventory/expiry-alerts')
        ->assertOk()
        ->assertJsonStructure([
            'expiring_by_threshold',
            'expired',
        ]);

    $data = $response->json();

    // B-15D (15 days) lands in the 30/60/90 groups; B-40D (40 days) only in 60/90.
    expect($data['expiring_by_threshold']['30'])->toHaveCount(1);
    expect($data['expiring_by_threshold']['60'])->toHaveCount(2);
    expect($data['expiring_by_threshold']['90'])->toHaveCount(2);

    // B-EXPIRED is expired.
    expect($data['expired'])->toHaveCount(1);
    expect($data['expired'][0]['batch_number'])->toBe('B-EXPIRED');
});

it('flags an expired batch and marks it unavailable for dispensing', function () {
    $item = InventoryItem::factory()->create([
        'item_name' => 'Expired Medicine',
        'unit_price' => 10,
        'stock_quantity' => 0,
        'reorder_level' => 0,
    ]);

    InventoryBatch::factory()->expired()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-EXPIRED',
        'expiry_date' => now()->subDays(30)->toDateString(),
        'initial_quantity' => 50,
        'current_quantity' => 50,
    ]);

    $this->artisan('clinic:scan-expiring-stock');

    $this->assertDatabaseHas('stock_alerts', [
        'alert_type' => 'expired',
        'threshold_days' => 0,
    ]);

    // A batch past its expiry date is not offered as a dispensing source.
    expect(InventoryBatch::where('batch_number', 'B-EXPIRED')->first()->isAvailable())->toBeFalse();
});

it('alerts a batch expiring in exactly the threshold window but not beyond it', function () {
    $item = InventoryItem::factory()->create([
        'item_name' => 'Boundary Item',
        'unit_price' => 10,
        'stock_quantity' => 0,
        'reorder_level' => 0,
    ]);

    // Expires in exactly 30 days: inside the 30-day window.
    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-EXACT-30',
        'expiry_date' => now()->addDays(30)->toDateString(),
        'initial_quantity' => 10,
        'current_quantity' => 10,
    ]);

    // Expires in 31 days: outside the 30-day window, inside the 60-day one.
    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-DAY-31',
        'expiry_date' => now()->addDays(31)->toDateString(),
        'initial_quantity' => 10,
        'current_quantity' => 10,
    ]);

    $this->artisan('clinic:scan-expiring-stock')
        ->assertExitCode(0);

    $exact = InventoryBatch::where('batch_number', 'B-EXACT-30')->first();
    $beyond = InventoryBatch::where('batch_number', 'B-DAY-31')->first();

    expect(StockAlert::where('inventory_batch_id', $exact->id)->where('threshold_days', 30)->exists())->toBeTrue()
        ->and(StockAlert::where('inventory_batch_id', $beyond->id)->where('threshold_days', 30)->exists())->toBeFalse()
        ->and(StockAlert::where('inventory_batch_id', $beyond->id)->where('threshold_days', 60)->exists())->toBeTrue();
});

it('the expiry scan is registered on the schedule', function () {
    $scheduled = collect(app('Illuminate\Console\Scheduling\Schedule')->events())
        ->filter(fn ($event) => str_contains($event->command, 'clinic:scan-expiring-stock'))
        ->first();

    expect($scheduled)->not->toBeNull();
});
