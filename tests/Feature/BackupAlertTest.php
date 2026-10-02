<?php

use App\Models\SystemBackup;
use App\Models\User;
use App\Services\Messaging\TwilioSmsDriver;
use App\Services\Messaging\WhatsAppCloudApiDriver;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Backup-failure alerting: `clinic:backup` must fail loudly (non-zero exit +
 * `failed` SystemBackup record), page the ops contact over SMS when it fails,
 * and never let an alert-path failure mask the backup failure itself.
 *
 * Same throwaway file-backed SQLite strategy as BackupRestoreCLITest so a
 * successful `clinic:backup` runs the real file-copy path; failure tests point
 * the export source at a missing file to force generation to throw.
 */
beforeEach(function (): void {
    $this->cliDbFile = tempnam(sys_get_temp_dir(), 'bk_alert_').'.sqlite';

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

/**
 * Distinct name: `enableTwilio()` already exists globally in
 * MessagingAndRemindersTest.php and duplicating it would fatal.
 */
function enableBackupAlertTwilio(): void
{
    config([
        'messaging.drivers.sms.driver' => TwilioSmsDriver::class,
        'messaging.drivers.sms.enabled' => true,
        'messaging.drivers.sms.sid' => 'AC-backup-alert',
        'messaging.drivers.sms.token' => 'test-token',
        'messaging.drivers.sms.from' => '+15550000111',
        'messaging.drivers.whatsapp.driver' => WhatsAppCloudApiDriver::class,
        'messaging.drivers.whatsapp.enabled' => false,
    ]);
}

/** Point the backup export source at a missing file so generation throws. */
function breakSqliteExportSource(string $path = '/nonexistent/clinic.sqlite'): void
{
    config(['database.connections.sqlite.database' => $path]);
}

test('a failed clinic:backup pages the ops contact via SMS and records the failure', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM-test'], 201)]);
    enableBackupAlertTwilio();

    $admin = User::factory()->create(['role' => 'Admin', 'phone' => '+15550009999']);

    breakSqliteExportSource();

    $this->artisan('clinic:backup')
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Backup failed');

    // The SystemBackup row reflects the failure, not a silent 'pending'.
    expect(SystemBackup::query()->failed()->count())->toBe(1);

    Http::assertSent(function (Request $request) use ($admin): bool {
        return str_contains($request->url(), '/Messages.json')
            && $request['To'] === $admin->phone
            && str_contains($request['Body'], 'Automated backup failed')
            && str_contains($request['Body'], 'clinic:list-backups');
    });

    $this->assertDatabaseHas('notification_logs', [
        'user_id' => $admin->id,
        'channel' => 'sms',
        'title' => 'BACKUP FAILED — '.config('app.name', 'ClinicSuite'),
        'status' => 'sent',
    ]);
});

test('CLINIC_BACKUP_ALERT_SMS_TO overrides the admin phone as the alert recipient', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM-test'], 201)]);
    enableBackupAlertTwilio();

    User::factory()->create(['role' => 'Admin', 'phone' => '+15550009999']);
    config(['clinic.backup_alert_sms_to' => '+15550007777']);

    breakSqliteExportSource();

    $this->artisan('clinic:backup')->assertExitCode(Command::FAILURE);

    Http::assertSent(fn (Request $request): bool => $request['To'] === '+15550007777');
});

test('an alert-send failure is recorded and cannot mask the backup failure', function () {
    // Twilio returns 500: the provider is down, but the command must still
    // exit with the *backup* failure and record both outcomes.
    Http::fake(['api.twilio.com/*' => Http::response([], 500)]);
    enableBackupAlertTwilio();

    $admin = User::factory()->create(['role' => 'Admin', 'phone' => '+15550009999']);

    breakSqliteExportSource();

    $this->artisan('clinic:backup')
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Backup failed');

    expect(SystemBackup::query()->failed()->count())->toBe(1);

    $this->assertDatabaseHas('notification_logs', [
        'user_id' => $admin->id,
        'channel' => 'sms',
        'status' => 'failed',
    ]);
});

test('an exception inside the alert path cannot crash the backup command', function () {
    enableBackupAlertTwilio();

    User::factory()->create(['role' => 'Admin', 'phone' => '+15550009999']);

    $this->mock(NotificationService::class, function ($mock): void {
        $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('provider down'));
    });

    breakSqliteExportSource();

    // The backup failure itself is what surfaces: exit 1, message printed,
    // failed row recorded — no crash from the alert side, no masking.
    $this->artisan('clinic:backup')
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Backup failed');

    expect(SystemBackup::query()->failed()->count())->toBe(1);
});

test('a successful clinic:backup sends no alert', function () {
    Http::fake(['api.twilio.com/*' => Http::response([], 201)]);
    enableBackupAlertTwilio();

    User::factory()->create(['role' => 'Admin', 'phone' => '+15550009999']);

    $this->artisan('clinic:backup')->assertSuccessful();

    expect(SystemBackup::query()->completed()->count())->toBe(1);

    Http::assertNothingSent();
    expect(DB::table('notification_logs')->where('channel', 'sms')->count())->toBe(0);
});

test('clinic:backup without an admin user fails loudly without attempting an SMS', function () {
    Http::fake();
    enableBackupAlertTwilio();

    $this->artisan('clinic:backup')
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('No admin user');

    Http::assertNothingSent();
    expect(DB::table('notification_logs')->count())->toBe(0);
});
