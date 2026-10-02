<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AppHealthCheckCommand extends Command
{
    protected $signature = 'app:health-check';

    protected $description = 'Process-only liveness check (no database, no ruleset)';

    /**
     * Liveness, not readiness. This must never depend on an external
     * dependency: a database blip must not restart every pod. It only confirms
     * the process can boot the framework. Readiness (DB reachability, the
     * clinical ruleset) is reported separately via `app:readiness-check`.
     */
    public function handle(): int
    {
        $this->line('ok');

        return Command::SUCCESS;
    }
}
