<?php

use App\Console\Commands\ClinicBackupCommand;
use App\Console\Commands\ScanExpiringStockCommand;
use App\Console\Commands\SendAppointmentRemindersCommand;
use App\Console\Commands\SendFollowupRemindersCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(ClinicBackupCommand::class)
    ->daily()
    ->withoutOverlapping();

Schedule::command(SendAppointmentRemindersCommand::class)
    ->everySixHours()
    ->withoutOverlapping();

Schedule::command(SendFollowupRemindersCommand::class)
    ->everySixHours()
    ->withoutOverlapping();

Schedule::command(ScanExpiringStockCommand::class)
    ->daily()
    ->withoutOverlapping();
