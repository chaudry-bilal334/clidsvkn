<?php

namespace App\Console\Commands;

use App\Models\InventoryBatch;
use App\Models\StockAlert;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class ScanExpiringStockCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'clinic:scan-expiring-stock';

    /**
     * The console command description.
     */
    protected $description = 'Scan inventory for expiring and expired batches; create idempotent alerts.';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting inventory expiry-scan...');

        $thresholds = Config::get('inventory.expiry_alert_thresholds', [30, 60, 90]);
        $pharmacists = User::whereIn('role', ['Pharmacist', 'Admin'])->get();

        if ($pharmacists->isEmpty()) {
            $this->warn('No users with pharmacy roles found; alerts are recorded but not delivered.');

            Log::channel('audit')->warning('expiry-scan: no pharmacy users', [
                'action' => 'scan_expiring_stock',
            ]);
        }

        $notifications = app(NotificationService::class);

        // 1. Epiring batches within each threshold
        foreach ($thresholds as $thresholdDays) {
            $now = now();
            $windowEnd = $now->copy()->addDays($thresholdDays);

            $batches = InventoryBatch::whereNotNull('expiry_date')
                ->where('expiry_date', '>=', $now)
                ->where('expiry_date', '<=', $windowEnd)
                ->where('current_quantity', '>', 0)
                ->get();

            foreach ($batches as $batch) {
                // Idempotency: check if alert already exists for this batch + threshold + type
                $existing = StockAlert::where('inventory_batch_id', $batch->id)
                    ->where('threshold_days', $thresholdDays)
                    ->where('alert_type', 'expiring')
                    ->exists();

                if ($existing) {
                    continue;
                }

                // Create the alert record. The unique index is the idempotency
                // guarantee; a concurrent scan that loses the race is ignored.
                try {
                    $alert = StockAlert::create([
                        'inventory_batch_id' => $batch->id,
                        'threshold_days' => $thresholdDays,
                        'alert_type' => 'expiring',
                        'message' => sprintf(
                            'Batch %s of %s expires on %s (within %d days); current quantity: %.2f',
                            $batch->batch_number,
                            $batch->item?->name ?? 'unknown item',
                            $batch->expiry_date->toDateString(),
                            $thresholdDays,
                            $batch->current_quantity,
                        ),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }

                // Send notification to pharmacy users
                $title = 'Stock expiring soon — '.($batch->item?->name ?? 'Inventory item');

                $body = sprintf(
                    'Batch %s of %s expires on %s (within %d days). Current quantity: %.2f. Consider restocking or adjusting dispensing rules.',
                    $batch->batch_number,
                    $batch->item?->name ?? 'unknown item',
                    $batch->expiry_date->toDateString(),
                    $thresholdDays,
                    $batch->current_quantity,
                );

                foreach ($pharmacists as $user) {
                    if ($user->consentsTo('sms')) {
                        try {
                            $deliveries = $notifications->send($user, 'sms', $title, $body);

                            Log::channel('audit')->info('Expiry alert dispatched', [
                                'action' => 'scan_expiring_stock',
                                'batch_id' => $batch->id,
                                'user_id' => $user->id,
                                'deliveries' => $deliveries,
                            ]);
                        } catch (\Throwable $e) {
                            Log::channel('audit')->error('Expiry alert send failed', [
                                'action' => 'scan_expiring_stock',
                                'batch_id' => $batch->id,
                                'user_id' => $user->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        }

        // 2. Expired batches (expiry date already passed)
        $expiredBatches = InventoryBatch::whereNotNull('expiry_date')
            ->where('expiry_date', '<', now())
            ->where('current_quantity', '>', 0)
            ->get();

        foreach ($expiredBatches as $batch) {
            // Idempotency: check if expired alert already exists for this batch
            $existing = StockAlert::where('inventory_batch_id', $batch->id)
                ->where('threshold_days', 0)
                ->where('alert_type', 'expired')
                ->exists();

            if ($existing) {
                continue;
            }

            // Create the alert record (unique index guards against a raced scan)
            try {
                $alert = StockAlert::create([
                    'inventory_batch_id' => $batch->id,
                    'threshold_days' => 0,
                    'alert_type' => 'expired',
                    'message' => sprintf(
                        'Batch %s of %s is expired (expired on %s); current quantity: %.2f',
                        $batch->batch_number,
                        $batch->item?->name ?? 'unknown item',
                        $batch->expiry_date?->toDateString() ?? 'unknown',
                        $batch->current_quantity,
                    ),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            // Send notification to pharmacy users
            $title = 'Expired stock — '.($batch->item?->name ?? 'Inventory item');

            $body = sprintf(
                'Batch %s of %s is expired (expired on %s). Current quantity: %.2f. Remove from dispensing options immediately.',
                $batch->batch_number,
                $batch->item?->name ?? 'unknown item',
                $batch->expiry_date?->toDateString() ?? 'unknown',
                $batch->current_quantity,
            );

            foreach ($pharmacists as $user) {
                if ($user->consentsTo('sms')) {
                    try {
                        $deliveries = $notifications->send($user, 'sms', $title, $body);

                        Log::channel('audit')->info('Expired stock alert dispatched', [
                            'action' => 'scan_expiring_stock',
                            'batch_id' => $batch->id,
                            'user_id' => $user->id,
                            'deliveries' => $deliveries,
                        ]);
                    } catch (\Throwable $e) {
                        Log::channel('audit')->error('Expired stock alert send failed', [
                            'action' => 'scan_expiring_stock',
                            'batch_id' => $batch->id,
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        $this->info('Inventory expiry-scan complete.');

        Log::channel('audit')->info('expiry-scan complete', [
            'action' => 'scan_expiring_stock',
        ]);

        return Command::SUCCESS;
    }
}
