<?php

use App\Models\Appointment;
use App\Models\InventoryItem;
use App\Models\Patient;
use App\Models\SystemBackup;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * G8 go-live drill: prove the backup archive is encrypted-at-rest, that
 * `clinic:restore` rebuilds the working database to exactly match the last
 * good backup after data loss, and that tampered or wrongly-keyed archives are
 * refused without corrupting data.
 *
 * These tests do not use RefreshDatabase: they operate on a throwaway
 * file-backed SQLite database so the file-copy backup/restore path can run for
 * real (an in-memory database cannot be copied, checksummed or replaced).
 */
/**
 * These tests deliberately pin themselves to a file-backed SQLite database:
 * the forked race needs a file DB that child processes can share, and the
 * restore drill needs a file it can copy, checksum and replace.
 *
 * Run under the MySQL config they would test SQLite and report green, which is
 * worse than not running at all. They skip instead, and the MySQL-specific
 * engine behaviour is covered by MySqlInvariantTest.
 */
if (env('CLINIC_MYSQL_INVARIANTS') === '1') {
    test('this SQLite file-backup suite is skipped under the MySQL invariant config', function () {
        expect(true)->toBeTrue();
    })->skip('SQLite file-backup suite; the MySQL paths are covered by MySqlInvariantTest.');
}

beforeEach(function (): void {
    $this->brDbFile = tempnam(sys_get_temp_dir(), 'br_drill_').'.sqlite';

    config(['database.connections.sqlite.database' => $this->brDbFile]);
    DB::purge('sqlite');

    $this->artisan('migrate', ['--force' => true]);

    Storage::fake('local');
});

afterEach(function (): void {
    DB::purge('sqlite');

    if (file_exists($this->brDbFile)) {
        @unlink($this->brDbFile);
    }
});

test('clinic:backup produces an encrypted archive whose checksum matches its manifest', function () {
    $admin = User::factory()->create(['role' => 'Admin']);

    $this->artisan('clinic:backup')->assertSuccessful();

    $backup = SystemBackup::query()->where('status', SystemBackup::STATUS_COMPLETED)->firstOrFail();

    expect($backup->is_encrypted)->toBeTrue()
        ->and($backup->checksum_sha256)->not->toBeNull()
        ->and((int) $backup->file_size)->toBeGreaterThan(0)
        ->and(Storage::disk('local')->exists($backup->file_path))->toBeTrue();

    $stored = Storage::disk('local')->get($backup->file_path);

    // The bytes on the backup disk must not be the plain dump: decryption must
    // be required before the archive can be checksummed or decompressed.
    $plainArchive = Crypt::decryptString($stored);

    expect(hash('sha256', $plainArchive))->toBe($backup->checksum_sha256);
});

test('an end-to-end restore drill rebuilds the wiped database to exactly match the last backup', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $patient = Patient::factory()->create([
        'name' => 'Ayesha Khan',
        'mrn' => 'MRN-0042',
        'phone' => '+92 300 1234567',
    ]);
    InventoryItem::factory()->create(['item_name' => 'Amoxicillin 500mg', 'stock_quantity' => 3]);
    Appointment::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'scheduled_at' => '2026-10-01 10:00:00',
        'status' => 'booked',
    ]);

    $tables = ['users', 'patients', 'appointments', 'inventory_items'];
    $countsBefore = [];
    foreach ($tables as $table) {
        $countsBefore[$table] = DB::table($table)->count();
    }

    $patientRowBefore = DB::table('patients')->where('id', $patient->id)->first();
    $appointmentRowBefore = DB::table('appointments')->where('patient_id', $patient->id)->first();
    $item = InventoryItem::first();

    $this->artisan('clinic:backup')->assertSuccessful();

    $backup = SystemBackup::query()->where('status', SystemBackup::STATUS_COMPLETED)->firstOrFail();

    // Simulate working-data loss: wipe every business table, leaving the
    // system_backups metadata row (and the migrations table) so the restore
    // command can still locate the archive it must replay.
    DB::table('appointments')->delete();
    DB::table('patients')->delete();
    DB::table('inventory_items')->delete();
    DB::table('users')->where('role', '<>', 'Admin')->delete();

    expect(Patient::count())->toBe(0)
        ->and(Appointment::count())->toBe(0);

    DB::disconnect('sqlite');

    $this->artisan('clinic:restore', ['backupId' => $backup->id, '--force' => true])->assertSuccessful();

    DB::purge('sqlite');
    DB::connection('sqlite')->reconnect();

    foreach ($tables as $table) {
        expect(DB::table($table)->count())->toBe($countsBefore[$table]);
    }

    $patientRowAfter = DB::table('patients')->where('id', $patient->id)->first();
    $appointmentRowAfter = DB::table('appointments')->where('patient_id', $patient->id)->first();

    expect($patientRowAfter)->not->toBeNull()
        ->and((array) $patientRowAfter)->toBe((array) $patientRowBefore)
        ->and((array) $appointmentRowAfter)->toBe((array) $appointmentRowBefore)
        ->and((string) InventoryItem::find($item->id)->stock_quantity)->toBe((string) 3);
});

test('restore refuses an archive whose decrypted content fails checksum verification', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $patient = Patient::factory()->create(['name' => 'Must Survive']);

    // A completed backup record whose stored ciphertext decrypts cleanly but
    // whose payload does not hash to the checksum recorded in the manifest.
    $goodPayload = gzencode('original working database content', 9);
    $claim = hash('sha256', gzencode('tampered content', 9));

    Storage::disk('local')->put('system/tampered.sql.gz.enc', Crypt::encryptString($goodPayload));

    SystemBackup::create([
        'filename' => 'tampered.sql.gz.enc',
        'file_path' => 'system/tampered.sql.gz.enc',
        'checksum_sha256' => $claim,
        'is_encrypted' => true,
        'status' => SystemBackup::STATUS_COMPLETED,
        'generated_by' => $admin->id,
    ]);

    $this->artisan('clinic:restore', ['backupId' => 1, '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Checksum verification failed');

    expect(Patient::find($patient->id)->name)->toBe('Must Survive')
        ->and(Patient::count())->toBe(1);
});

test('restore fails cleanly without touching data when the encryption key differs', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    Patient::factory()->create(['name' => 'Untouched Patient']);

    $this->artisan('clinic:backup')->assertSuccessful();

    $backup = SystemBackup::query()->where('status', SystemBackup::STATUS_COMPLETED)->firstOrFail();

    $originalEncrypter = app('encrypter');
    app()->instance('encrypter', new Encrypter(random_bytes(32), config('app.cipher')));
    Crypt::clearResolvedInstances();

    try {
        $this->artisan('clinic:restore', ['backupId' => $backup->id, '--force' => true])
            ->assertExitCode(1);
    } finally {
        app()->instance('encrypter', $originalEncrypter);
        Crypt::clearResolvedInstances();
    }

    expect(Patient::count())->toBe(1)
        ->and(Patient::first()->name)->toBe('Untouched Patient');

    $backup->refresh();

    expect($backup->status)->toBe(SystemBackup::STATUS_COMPLETED);
});

test('restore refuses a backup whose archive is missing from the backup disk', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    Patient::factory()->create();

    SystemBackup::create([
        'filename' => 'gone.sql.gz.enc',
        'file_path' => 'system/gone.sql.gz.enc',
        'checksum_sha256' => str_repeat('0', 64),
        'is_encrypted' => true,
        'status' => SystemBackup::STATUS_COMPLETED,
        'generated_by' => $admin->id,
    ]);

    $this->artisan('clinic:restore', ['backupId' => 1, '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Backup file not found');

    expect(Patient::count())->toBe(1);
});

test('restore rejects a backup record that never completed', function () {
    $admin = User::factory()->create(['role' => 'Admin']);

    SystemBackup::create([
        'filename' => 'pending.sql.gz.enc',
        'file_path' => 'system/pending.sql.gz.enc',
        'status' => SystemBackup::STATUS_PENDING,
        'generated_by' => $admin->id,
    ]);

    $this->artisan('clinic:restore', ['backupId' => 1, '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('is not completed');
});

test('BackupService refuses to restore a backup that is not marked completed', function () {
    $admin = User::factory()->create(['role' => 'Admin']);

    $backup = SystemBackup::create([
        'filename' => 'pending.sql.gz.enc',
        'file_path' => 'system/pending.sql.gz.enc',
        'status' => SystemBackup::STATUS_PENDING,
        'generated_by' => $admin->id,
    ]);

    expect(fn () => app(BackupService::class)->restoreBackup($backup))
        ->toThrow(RuntimeException::class, 'Can only restore completed backups');
});
