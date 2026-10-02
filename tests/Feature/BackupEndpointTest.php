<?php

use App\Models\AuditLog;
use App\Models\SystemBackup;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

pest()->use(RefreshDatabase::class);

function bk_admin(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

/**
 * A completed backup row with its archive present on the backup disk.
 */
function bk_backup(?User $generator = null, string $contents = 'archive-bytes'): SystemBackup
{
    Storage::fake(config('filesystems.backup_disk', 'local'));

    $path = 'backups/backup-'.uniqid().'.sql.gz';
    Storage::disk(config('filesystems.backup_disk', 'local'))->put($path, $contents);

    return SystemBackup::create([
        'filename' => basename($path),
        'file_path' => $path,
        'file_size' => strlen($contents),
        'status' => SystemBackup::STATUS_COMPLETED,
        'generated_by' => ($generator ?? bk_admin())->id,
    ]);
}

test('a non-admin cannot reach any backup endpoint', function (string $method, string $route) {
    $receptionist = User::factory()->create(['role' => 'Receptionist']);
    $backup = bk_backup();

    $url = str_replace('{id}', (string) $backup->id, $route);

    $this->actingAs($receptionist)
        ->json($method, $url)
        ->assertForbidden();
})->with([
    ['GET', '/api/v1/backups'],
    ['POST', '/api/v1/backups'],
    ['GET', '/api/v1/backups/{id}/download'],
    ['DELETE', '/api/v1/backups/{id}'],
    ['POST', '/api/v1/backups/warm-cache'],
]);

test('listing backups exposes the size and created_at the admin UI binds to', function () {
    $admin = bk_admin();
    bk_backup($admin);

    $this->actingAs($admin)
        ->getJson('/api/v1/backups')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.size', '13.00')
        ->assertJsonPath('data.0.file_size', '13.00')
        ->assertJsonStructure([
            'data' => [['id', 'filename', 'status', 'size', 'file_size', 'generated_at', 'created_at']],
        ]);
});

test('generating a backup records the acting admin, not whichever user happens to be first', function () {
    // A lower-id non-admin must not be picked up as the actor.
    User::factory()->create(['role' => 'Receptionist']);
    $admin = bk_admin();
    $backup = bk_backup($admin);

    // The real service shells out to a database dump, which cannot run against
    // the in-memory test database.
    $this->mock(BackupService::class)
        ->shouldReceive('generateBackup')
        ->once()
        ->with($admin)
        ->andReturn($backup);

    $this->actingAs($admin)
        ->postJson('/api/v1/backups')
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $backup->id);

    $audit = AuditLog::query()->where('action', 'system.backup_generated')->sole();

    expect($audit->user_id)->toBe($admin->id)
        ->and($audit->auditable_type)->toBe((new SystemBackup)->getMorphClass())
        ->and($audit->auditable_id)->toBe($backup->id);
});

test('a failed backup is audited and reports no internal detail', function () {
    $admin = bk_admin();

    $this->mock(BackupService::class)
        ->shouldReceive('generateBackup')
        ->andThrow(new RuntimeException('/var/private/secrets.sql.gz: permission denied'));

    $response = $this->actingAs($admin)
        ->postJson('/api/v1/backups')
        ->assertStatus(500)
        ->assertJsonPath('success', false);

    expect($response->json('message'))->not->toContain('permission denied')
        ->and($response->json('message'))->not->toContain('/var/private')
        ->and(AuditLog::query()->where('action', 'system.backup_failed')->count())->toBe(1);
});

test('downloading a backup streams the file and is audited', function () {
    $admin = bk_admin();
    $backup = bk_backup($admin, 'payload');

    $this->actingAs($admin)
        ->get("/api/v1/backups/{$backup->id}/download")
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="'.$backup->filename.'"')
        ->assertHeader('Content-Length', '7');

    expect(AuditLog::query()->where('action', 'system.backup_downloaded')->count())->toBe(1);
});

test('downloading a backup whose file is gone returns 404 rather than a fatal', function () {
    $admin = bk_admin();
    $backup = bk_backup($admin);
    Storage::disk(config('filesystems.backup_disk', 'local'))->delete($backup->file_path);

    $this->actingAs($admin)
        ->getJson("/api/v1/backups/{$backup->id}/download")
        ->assertNotFound()
        ->assertJsonPath('success', false);
});

test('deleting a backup records which archive went away', function () {
    $admin = bk_admin();
    $backup = bk_backup($admin);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/backups/{$backup->id}")
        ->assertOk();

    expect(SystemBackup::whereKey($backup->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'system.backup_deleted')->count())->toBe(1);
});

test('warming the cache is audited', function () {
    $admin = bk_admin();

    $this->actingAs($admin)
        ->postJson('/api/v1/backups/warm-cache')
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(AuditLog::query()->where('action', 'system.cache_warmed')->count())->toBe(1);
});

test('every backup response uses the standard envelope', function () {
    $admin = bk_admin();
    bk_backup($admin);

    $this->actingAs($admin)
        ->getJson('/api/v1/backups')
        ->assertOk()
        ->assertJsonStructure(['success', 'data', 'meta' => ['request_id']]);
});
