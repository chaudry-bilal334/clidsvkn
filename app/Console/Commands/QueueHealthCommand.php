<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueueHealthCommand extends Command
{
    protected $signature = 'clinic:queue-health';

    protected $description = 'Report pending and failed queue job counts';

    public function handle(): int
    {
        $pending = 0;
        $failed = 0;

        try {
            $pending = (int) DB::table('jobs')->count();
        } catch (\Throwable) {
            $pending = 0;
        }

        try {
            $failed = (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $failed = 0;
        }

        $this->line("pending: {$pending}");
        $this->line("failed: {$failed}");

        return Command::SUCCESS;
    }
}
