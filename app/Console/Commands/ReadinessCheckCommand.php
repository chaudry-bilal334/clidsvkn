<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReadinessCheckCommand extends Command
{
    protected $signature = 'app:readiness-check';

    protected $description = 'Readiness check: database reachability (separate from liveness)';

    /**
     * Readiness, not liveness. Confirms the database is reachable so a pod is
     * only kept in rotation while it can actually serve traffic. This is wired
     * to the k8s readinessProbe, never the livenessProbe.
     */
    public function handle(): int
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error('database unreachable: '.$e->getMessage());

            return Command::FAILURE;
        }

        $this->line('ok');

        return Command::SUCCESS;
    }
}
