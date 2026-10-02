<?php

namespace App\Console\Commands;

use App\Jobs\SendFollowupReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clinic:send-followup-reminders {--hours=24 : Upcoming follow-up window in hours}')]
#[Description('Send follow-up reminders to assigned staff for due and overdue tasks')]
class SendFollowupRemindersCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        SendFollowupReminders::dispatchSync((int) $this->option('hours'));

        $this->info('Follow-up reminder run completed.');

        return self::SUCCESS;
    }
}
