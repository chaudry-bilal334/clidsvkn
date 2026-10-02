<?php

use App\Models\Patient;
use App\Models\SystemBackup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * RTO runbook CLI additions: `clinic:restore --latest` (operator restores the
 * newest completed backup without needing to look up its ID) and
 * `clinic:list-backups` (ID -> filename mapping for the runbook's first step).
 *
 * Same throwaway file-backed SQLite strategy as BackupRestoreDrillTest so the
 * file-copy backup/restore path runs for real.
 */
beforeEach(function (): void {
    $this->cliDbFile = tempnam(sys_get_temp_dir(), 'br_cli_').'.sqlite';

    config(['database.connections.sqlite.database' => $this->cliDbFile]);
    DB::purge('sqlite');

    $this->artisan('migrate', ['--force' => true]);

    Storage::fake('local');
});

afterEach(function (): void {
    DB::purge('sqlite');

    if (file_exists($this->cliDbFile)) {
        @unlink($this->cliDbFile);
    }
});

test('clinic:restore --latest restores the most recent completed backup', function () {
    User::factory()->create(['role' => 'Admin']);

    // Snapshot 1: 3 patients.
    Patient::factory()->count(3)->create();

    $this->artisan('clinic:backup')->assertSuccessful();

    // Snapshot 2: 4 patients (the newest backup).
    Patient::factory()->create();
    $this->artisan('clinic:backup')->assertSuccessful();

    $latest = SystemBackup::query()->completed()->orderByDesc('created_at')->orderByDesc('id')->first();
    expect($latest)->not->toBeNull();

    // Working-data loss.
    DB::table('patients')->delete();
    expect(Patient::count())->toBe(0);

    DB::disconnect('sqlite');

    $this->artisan('clinic:restore', ['--latest' => true, '--force' => true])->assertSuccessful();

    DB::purge('sqlite');
    DB::connection('sqlite')->reconnect();

    expect(Patient::count())->toBe(4);
});

test('clinic:restore --latest fails with guidance when no backup has ever completed', function () {
    User::factory()->create(['role' => 'Admin']);
    Patient::factory()->create();

    $this->artisan('clinic:restore', ['--latest' => true, '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('No completed backup found to restore.')
        ->expectsOutputToContain('clinic:list-backups');

    // Data untouched by the failed restore.
    expect(Patient::count())->toBe(1);
});

test('clinic:restore --latest with no backup ID or flag explains the required invocation', function () {
    $this->artisan('clinic:restore')
        ->assertExitCode(1)
        ->expectsOutputToContain('Specify a backup ID or pass --latest.');
});

test('clinic:list-backups maps backup IDs to filenames', function () {
    $admin = User::factory()->create(['role' => 'Admin']);

    SystemBackup::create([
        'filename' => 'manual.sql.gz.enc',
        'file_path' => 'system/manual.sql.gz.enc',
        'checksum_sha256' => str_repeat('0', 64),
        'is_encrypted' => true,
        'status' => SystemBackup::STATUS_COMPLETED,
        'generated_by' => $admin->id,
    ]);

    $this->artisan('clinic:list-backups')
        ->assertSuccessful()
        ->expectsOutputToContain('manual.sql.gz.enc');
});

test('clinic:list-backups reports when the archive store is empty', function () {
    User::factory()->create(['role' => 'Admin']);

    $this->artisan('clinic:list-backups')
        ->assertSuccessful()
        ->expectsOutputToContain('No backups have been created yet.');
});
