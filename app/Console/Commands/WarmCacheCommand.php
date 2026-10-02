<?php

namespace App\Console\Commands;

use App\Services\CacheWarmingService;
use Illuminate\Console\Command;

class WarmCacheCommand extends Command
{
    protected $signature = 'clinic:warm-cache';

    protected $description = 'Re-populate application caches after a deployment';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(CacheWarmingService $cacheWarmingService): int
    {
        $this->info('Warming application caches...');

        try {
            $cacheWarmingService->warmAll();

            $this->info('Cache warming completed.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Cache warming failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
