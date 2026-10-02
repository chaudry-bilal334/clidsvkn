<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clinic:list-backups')]
#[Description('List all database backups')]
class ListBackups extends Command
{
    public function __construct(
        private readonly BackupService $backupService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $backups = $this->backupService->listBackups();

        if ($backups->isEmpty()) {
            $this->info('No backups have been created yet.');

            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Filename', 'Size', 'Status', 'Created'],
            $backups->map(fn ($backup) => [
                $backup->id,
                $backup->filename,
                $this->backupService->formatBytes($backup->file_size),
                $backup->status,
                $backup->created_at?->toDateTimeString(),
            ])
        );

        return Command::SUCCESS;
    }
}
