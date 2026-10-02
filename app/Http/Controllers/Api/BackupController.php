<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemBackup;
use App\Models\User;
use App\Services\BackupService;
use App\Services\CacheWarmingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BackupController extends Controller
{
    /**
     * The backup service instance.
     */
    protected BackupService $backupService;

    /**
     * The cache warming service instance.
     */
    protected CacheWarmingService $cacheWarmingService;

    /**
     * Create a new controller instance.
     */
    public function __construct(
        BackupService $backupService,
        CacheWarmingService $cacheWarmingService
    ) {
        $this->backupService = $backupService;
        $this->cacheWarmingService = $cacheWarmingService;
    }

    /**
     * List all system backups with file sizes and statuses.
     *
     * GET /api/v1/admin/backups
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SystemBackup::class);

        $backups = $this->backupService->listBackups();

        return $this->ok(
            $backups->map(fn (SystemBackup $backup): array => $this->presentBackup($backup))->all(),
            'Backups listed.',
        );
    }

    /**
     * Trigger an on-demand database backup.
     *
     * POST /api/v1/admin/backups
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('manage', SystemBackup::class);

        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->error('Unable to resolve the acting user for this backup.', 403);
        }

        try {
            $backup = $this->backupService->generateBackup($actor);
        } catch (Throwable $e) {
            report($e);

            AuditLog::recordSystem($actor, 'system.backup_failed', [
                'exception' => $e::class,
            ]);

            // Never surface the raw exception message: backup failures can carry
            // filesystem paths and cipher details (API_CONTRACT.md §5).
            return $this->error('Backup generation failed. Check the system logs for details.', 500);
        }

        AuditLog::record($actor, 'system.backup_generated', $backup, newValues: [
            'filename' => $backup->filename,
        ]);

        return $this->created($this->presentBackup($backup), 'Backup generated successfully.');
    }

    /**
     * Secure download stream for backup archives.
     *
     * GET /api/v1/admin/backups/{backup}/download
     *
     * @return Response|JsonResponse A streamed file on success, the standard
     *                               error envelope when the archive is missing.
     */
    public function download(SystemBackup $backup): Response|JsonResponse
    {
        $this->authorize('download', $backup);

        $diskName = config('filesystems.backup_disk', 'local');

        if (! Storage::disk($diskName)->exists($backup->file_path)) {
            return $this->error('Backup file not found on storage.', 404);
        }

        $file = Storage::disk($diskName)->get($backup->file_path);
        $fileSize = Storage::disk($diskName)->size($backup->file_path);

        $actor = request()->user();

        AuditLog::record($actor instanceof User ? $actor : null, 'system.backup_downloaded', $backup, newValues: [
            'file_size' => $fileSize,
        ]);

        return response($file, 200)
            ->header('Content-Type', 'application/gzip')
            ->header('Content-Disposition', 'attachment; filename="'.$backup->filename.'"')
            ->header('Content-Length', (string) $fileSize);
    }

    /**
     * Delete old backup files from storage.
     *
     * DELETE /api/v1/admin/backups/{backup}
     */
    public function destroy(SystemBackup $backup): JsonResponse
    {
        $this->authorize('delete', $backup);

        $actor = request()->user();
        $identifier = $backup->id;
        $filename = $backup->filename;

        if (! $this->backupService->deleteBackup($backup)) {
            return $this->error('Failed to delete backup.', 500);
        }

        AuditLog::recordSystem(
            $actor instanceof User ? $actor : null,
            'system.backup_deleted',
            ['backup_id' => $identifier, 'filename' => $filename],
        );

        return $this->ok(null, 'Backup deleted successfully.');
    }

    /**
     * Warm cache on demand.
     *
     * POST /api/v1/admin/backups/warm-cache
     */
    public function warmCache(Request $request): JsonResponse
    {
        $this->authorize('manage', SystemBackup::class);

        try {
            $this->cacheWarmingService->warmAll();
        } catch (Throwable $e) {
            report($e);

            return $this->error('Cache warming failed. Check the system logs for details.', 500);
        }

        $actor = $request->user();

        AuditLog::recordSystem($actor instanceof User ? $actor : null, 'system.cache_warmed');

        return $this->ok(null, 'Cache warmed successfully.');
    }

    /**
     * Public-array projection for a backup row.
     *
     * `created_at` and `size` are aliases kept alongside the canonical
     * `generated_at` / `file_size` because the admin UI binds to them
     * (SystemAdminView BackupEntry).
     *
     * @return array<string, mixed>
     */
    private function presentBackup(SystemBackup $backup): array
    {
        $generatedAt = $backup->created_at?->toDateTimeString();

        return [
            'id' => $backup->id,
            'filename' => $backup->filename,
            'file_path' => $backup->file_path,
            'file_size' => $backup->file_size,
            'size' => $backup->file_size,
            'status' => $backup->status,
            'generated_by' => $backup->generator?->name,
            'generated_at' => $generatedAt,
            'created_at' => $generatedAt,
            'deleted_at' => $backup->deleted_at?->toDateTimeString(),
        ];
    }
}
