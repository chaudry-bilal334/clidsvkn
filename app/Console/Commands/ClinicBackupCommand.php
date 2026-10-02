<?php

namespace App\Console\Commands;

use App\Models\SystemBackup;
use App\Models\User;
use App\Services\BackupService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ClinicBackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'clinic:backup';

    /**
     * The console command description.
     */
    protected $description = 'Generate a database backup, rotate old backups, and SMS the ops contact when generation fails';

    /**
     * Execute the console command.
     */
    public function handle(BackupService $backupService, NotificationService $notifications): int
    {
        $this->info('Starting automated system backup...');

        // Get the admin user who triggered the backup
        $admin = User::where('role', 'Admin')->first();

        if (! $admin) {
            $message = 'No admin user found to attribute the backup to.';
            $this->error($message);

            // Scheduled runs discard stdout, so the daily log is the only
            // record of an aborted run — write it loudly.
            Log::channel('audit')->error('system_backup aborted: no admin user', [
                'action' => 'system_backup',
            ]);

            return Command::FAILURE;
        }

        try {
            $backup = $backupService->generateBackup($admin);

            $this->info('Backup completed successfully.');
            $this->info('Backup ID: '.$backup->id);
            $this->info('Filename: '.$backup->filename);
            $this->info('Status: '.$backup->status);
            $this->info('File size: '.number_format($backup->file_size ?? 0).' bytes');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            $this->error('Please check the logs for more details.');

            $this->alertBackupFailure($notifications, $e, $admin);

            return Command::FAILURE;
        }
    }

    /**
     * Page the ops contact when the backup fails. Failures here are logged and
     * swallowed on purpose: alerting must never mask the backup failure itself
     * or crash the scheduled job.
     */
    private function alertBackupFailure(NotificationService $notifications, \Throwable $e, User $admin): void
    {
        $backupId = SystemBackup::query()->failed()->latest('id')->value('id') ?? 'n/a';
        $to = config('clinic.backup_alert_sms_to') ?: $admin->phone;

        $message = $e->getMessage();
        if (mb_strlen($message) > 180) {
            $message = mb_substr($message, 0, 180).'…';
        }

        $title = 'BACKUP FAILED — '.config('app.name', 'ClinicSuite');

        $body = 'Automated backup failed (backup #'.$backupId.') on '.now()->toDateTimeString().': '
            .$message
            .' Verify the newest archive with: php artisan clinic:list-backups. Then retry: php artisan clinic:backup.';

        try {
            $deliveries = $notifications->send($admin, 'sms', $title, $body, filled($to) ? $to : null);

            Log::channel('audit')->info('Backup failure alert dispatched', [
                'action' => 'system_backup',
                'backup_id' => $backupId,
                'recipient' => filled($to) ? $to : '(admin has no phone)',
                'deliveries' => $deliveries,
            ]);
        } catch (\Throwable $alertException) {
            Log::channel('audit')->error('Backup failure alert could not be sent', [
                'action' => 'system_backup',
                'backup_id' => $backupId,
                'error' => $alertException->getMessage(),
                'recipient' => filled($to) ? $to : '(admin has no phone)',
            ]);
        }
    }
}
