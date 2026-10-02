<?php

namespace App\Console\Commands;

use App\Jobs\SendAppointmentReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clinic:send-reminders {--hours=24 : Upcoming appointment window in hours}')]
#[Description('Send appointment reminders for the next 24 hours')]
class SendAppointmentRemindersCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        SendAppointmentReminders::dispatchSync((int) $this->option('hours'));

        $this->info('Appointment reminder run completed.');

        return self::SUCCESS;
    }
}
