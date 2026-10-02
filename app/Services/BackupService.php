<?php

namespace App\Services;

use App\Models\SystemBackup;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackupService
{
    /**
     * Generate a database backup and store it securely with encryption.
     *
     * Uses mysqldump for database export (MySQL) or file copy (SQLite),
     * compresses with gzip, encrypts with Laravel's Crypt,
     * stores on the configured backup disk, and creates a SystemBackup record.
     */
    public function generateBackup(User $generatedBy): SystemBackup
    {
        $backup = SystemBackup::create([
            'filename' => 'backup-'.now()->format('YmdHis').'.sql.gz.enc',
            'file_path' => 'backups/'.now()->format('YmdHis').'.sql.gz.enc',
            'status' => SystemBackup::STATUS_PENDING,
            'generated_by' => $generatedBy->id,
        ]);

        try {
            $this->log('Starting database backup generation', ['backup_id' => $backup->id]);

            $timestamp = now()->format('Y_m_d_His');
            $dumpFile = tempnam(sys_get_temp_dir(), 'db_dump_');

            // Export database based on driver
            $driver = config('database.default');
            $this->exportDatabase($driver, $dumpFile);

            // Compress the dump file
            $compressedFile = $dumpFile.'.gz';
            $compressOutput = [];
            $compressReturnCode = 0;
            $compressCommand = "gzip -9 -c {$dumpFile} > {$compressedFile}";
            exec($compressCommand, $compressOutput, $compressReturnCode);

            if ($compressReturnCode !== 0) {
                throw new \RuntimeException('gzip compression failed');
            }

            // Calculate SHA-256 checksum before encryption
            $checksum = hash_file('sha256', $compressedFile);

            // Encrypt the compressed file
            $encryptedFile = $compressedFile.'.enc';
            $encryptedContent = Crypt::encryptString(file_get_contents($compressedFile));
            file_put_contents($encryptedFile, $encryptedContent);

            // Derive the size from the encrypted payload itself rather than a
            // disk stat: filesize() can race file writes on some filesystems
            // and silently yield false, corrupting the backup manifest.
            $fileSize = strlen($encryptedContent);

            // Store on backup disk
            $diskName = config('filesystems.backup_disk', 'local');
            $remotePath = 'system/'.basename($encryptedFile);

            $stored = Storage::disk($diskName)->put($remotePath, file_get_contents($encryptedFile));

            // Clean up temp files
            @unlink($dumpFile);
            @unlink($compressedFile);
            @unlink($encryptedFile);

            if (! $stored) {
                throw new \RuntimeException('Failed to store backup file');
            }

            // Update backup record with completed status, file size, checksum, and encryption flag
            $backup->update([
                'filename' => basename($encryptedFile),
                'file_path' => $remotePath,
                'file_size' => $fileSize,
                'checksum_sha256' => $checksum,
                'is_encrypted' => true,
                'status' => SystemBackup::STATUS_COMPLETED,
            ]);

            $this->log('Database backup completed', [
                'backup_id' => $backup->id,
                'file_size' => $fileSize,
                'file_path' => $remotePath,
                'checksum_sha256' => $checksum,
            ]);

            // Rotate old backups (keep last 30 days)
            $this->rotateOldBackups();

            return $backup;

        } catch (\Throwable $e) {
            // Mark backup as failed
            $backup->update([
                'status' => SystemBackup::STATUS_FAILED,
            ]);

            $this->log('Database backup failed', [
                'backup_id' => $backup->id,
                'error' => $e->getMessage(),
            ], true);

            throw $e;
        }
    }

    /**
     * Export database based on the configured driver.
     */
    private function exportDatabase(string $driver, string $dumpFile): void
    {
        if ($driver === 'sqlite') {
            $databasePath = config('database.connections.sqlite.database');
            if (! $databasePath || ! file_exists($databasePath)) {
                throw new \RuntimeException('SQLite database file not found: '.($databasePath ?? 'unknown'));
            }
            copy($databasePath, $dumpFile);

            return;
        }

        // MySQL/MariaDB using mysqldump
        $connection = config('database.connections.'.$driver);
        $database = $connection['database'];
        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? '3306';
        $username = $connection['username'];
        $password = $connection['password'] ?? '';

        $dumpCommand = "mysqldump -h {$host} -P {$port} -u {$username} ";
        if ($password) {
            $dumpCommand .= "-p'{$password}' ";
        }
        $dumpCommand .= "--single-transaction --routines --triggers {$database} > {$dumpFile}";

        $output = [];
        $returnCode = 0;
        exec($dumpCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('mysqldump failed: '.implode("\n", $output));
        }
    }

    /**
     * Restore a database from an encrypted backup file.
     *
     * Downloads the backup, verifies checksum, decrypts, decompresses,
     * and restores the database. Returns the path to the restored dump file.
     */
    public function restoreBackup(SystemBackup $backup): string
    {
        if ($backup->status !== SystemBackup::STATUS_COMPLETED) {
            throw new \RuntimeException('Can only restore completed backups');
        }

        $diskName = config('filesystems.backup_disk', 'local');
        $backupDisk = Storage::disk($diskName);

        if (! $backupDisk->exists($backup->file_path)) {
            throw new \RuntimeException('Backup file not found in storage');
        }

        $this->log('Starting database restore', ['backup_id' => $backup->id]);

        // Download encrypted file
        $encryptedFile = tempnam(sys_get_temp_dir(), 'backup_enc_');
        $encryptedContent = $backupDisk->get($backup->file_path);
        file_put_contents($encryptedFile, $encryptedContent);

        try {
            // Decrypt
            $decryptedContent = Crypt::decryptString($encryptedContent);
            $compressedFile = tempnam(sys_get_temp_dir(), 'backup_gz_');
            file_put_contents($compressedFile, $decryptedContent);

            // Verify checksum if available
            if ($backup->checksum_sha256) {
                $actualChecksum = hash_file('sha256', $compressedFile);
                if (! hash_equals($backup->checksum_sha256, $actualChecksum)) {
                    throw new \RuntimeException('Checksum verification failed - backup may be corrupted');
                }
            }

            // Decompress
            $dumpFile = tempnam(sys_get_temp_dir(), 'backup_sql_');
            $decompressOutput = [];
            $decompressReturnCode = 0;
            $decompressCommand = "gzip -d -c {$compressedFile} > {$dumpFile}";
            exec($decompressCommand, $decompressOutput, $decompressReturnCode);

            if ($decompressReturnCode !== 0) {
                throw new \RuntimeException('gzip decompression failed');
            }

            // Restore database based on driver
            $driver = config('database.default');
            $this->importDatabase($driver, $dumpFile);

            // Clean up temp files
            @unlink($encryptedFile);
            @unlink($compressedFile);
            @unlink($dumpFile);

            $this->log('Database restore completed', ['backup_id' => $backup->id]);

            return $dumpFile;

        } catch (\Throwable $e) {
            // Clean up on error
            @unlink($encryptedFile);
            @unlink($compressedFile);
            @unlink($dumpFile ?? null);
            throw $e;
        }
    }

    /**
     * Import database based on the configured driver.
     */
    private function importDatabase(string $driver, string $dumpFile): void
    {
        if ($driver === 'sqlite') {
            $databasePath = config('database.connections.sqlite.database');
            if (! $databasePath) {
                throw new \RuntimeException('SQLite database path not configured');
            }
            // Backup current database
            $backupPath = $databasePath.'.bak.'.time();
            copy($databasePath, $backupPath);
            // Replace with restored dump
            copy($dumpFile, $databasePath);
            @unlink($backupPath);

            return;
        }

        // MySQL/MariaDB using mysql client
        $connection = config('database.connections.'.$driver);
        $database = $connection['database'];
        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? '3306';
        $username = $connection['username'];
        $password = $connection['password'] ?? '';

        $importCommand = "mysql -h {$host} -P {$port} -u {$username} ";
        if ($password) {
            $importCommand .= "-p'{$password}' ";
        }
        $importCommand .= "{$database} < {$dumpFile}";

        $output = [];
        $returnCode = 0;
        exec($importCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('mysql import failed: '.implode("\n", $output));
        }
    }

    /**
     * Rotate old backups, keeping only the last 30 days.
     *
     * Removes backup files older than 30 days from the storage disk.
     */
    protected function rotateOldBackups(): void
    {
        $backupDisk = config('filesystems.backup_disk', 'local');

        if (! Storage::disk($backupDisk)->exists('system')) {
            return;
        }

        $cutoffDate = now()->subDays(30);
        $files = Storage::disk($backupDisk)->allFiles('system');

        foreach ($files as $filePath) {
            $lastModified = Storage::disk($backupDisk)->lastModified($filePath);
            $modifiedAt = Carbon::createFromTimestamp($lastModified);
            if ($modifiedAt->lt($cutoffDate)) {
                Storage::disk($backupDisk)->delete($filePath);

                $this->log('Rotated old backup', ['file' => $filePath]);
            }
        }
    }

    /**
     * List all backups with their details.
     *
     * @return Collection<int, SystemBackup>
     */
    public function listBackups(): Collection
    {
        return SystemBackup::with('generator')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Human-readable file size (shared by the restore/list CLI output).
     */
    public function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'N/A';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Delete a specific backup file from storage.
     */
    public function deleteBackup(SystemBackup $backup): bool
    {
        $success = Storage::disk('local')->delete($backup->file_path);

        if ($success) {
            $backup->delete();
        }

        return $success;
    }

    /**
     * Get a backup file for download.
     *
     * @return string|false
     */
    public function getBackupFile(SystemBackup $backup)
    {
        $diskName = config('filesystems.backup_disk', 'local');

        if (! Storage::disk($diskName)->exists($backup->file_path)) {
            return false;
        }

        return Storage::disk($diskName)->get($backup->file_path);
    }

    /**
     * Log backup actions to the audit channel.
     */
    protected function log(string $message, array $context = [], bool $exception = false): void
    {
        $payload = [
            'action' => 'system_backup',
            'message' => $message,
            ...$context,
        ];

        if ($exception) {
            \Log::channel('audit')->error($message, $payload);
        } else {
            \Log::channel('audit')->info($message, $payload);
        }
    }
}
