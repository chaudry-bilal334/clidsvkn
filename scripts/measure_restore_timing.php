<?php

/**
 * RTO timing measurement for the backup/restore pipeline.
 *
 * Seeds a realistic-sized dataset into a THROWAWAY file-backed SQLite
 * database, generates a real backup through the real `clinic:backup` command,
 * then times each restore phase (locate/download, decrypt, checksum verify,
 * decompress, import) using the exact same primitives the BackupService
 * restore path uses, and finally times the real `clinic:restore --force`
 * command end-to-end.
 *
 * The dev database file is never touched: the connection is pointed at a
 * temporary SQLite file that is deleted when the run finishes. SQLite copy
 * import is a lower-bound estimate for MySQL; staging validation (G4) must
 * measure the `mysql < dump` import on real data.
 *
 * Run from backend_laravel/ (standalone PHP, boots Laravel directly):
 *
 *   php scripts/measure_restore_timing.php
 *
 * Optional sizing via env:
 *   RTO_PATIENTS=5000 RTO_APPOINTMENTS=30000 php scripts/measure_restore_timing.php
 */

use App\Models\Patient;
use App\Models\SystemBackup;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$patientsTarget = (int) (getenv('RTO_PATIENTS') ?: 5000);
$appointmentsTarget = (int) (getenv('RTO_APPOINTMENTS') ?: 30000);

$tmpDb = sys_get_temp_dir().'/rto_timing_'.uniqid().'.sqlite';
$tmpDir = sys_get_temp_dir().'/rto_timing_'.uniqid();
mkdir($tmpDir, 0777, true);

function rtoMs(float $micros): string
{
    return sprintf('%.1f ms', $micros / 1000);
}

function rtoElapsed(float $startNs): string
{
    return rtoMs((hrtime(true) - $startNs) / 1000);
}

/**
 * Best-effort cleanup: remove the throwaway DB, temp files, and any backup
 * archives this run created on the real backup disk.
 */
function rtoCleanup(string $tmpDb, string $tmpDir, array $tmpFiles, ?SystemBackup $backup = null): void
{
    foreach ($tmpFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @unlink($tmpDb);
    @rmdir($tmpDir);

    if ($backup !== null) {
        try {
            Storage::disk('local')->delete($backup->file_path);
        } catch (Throwable) {
            // best effort
        }
    }
}

$tmpFiles = [];
$backup = null;

try {
    echo "== RTO timing run (SQLite lower-bound) ==\n";
    echo 'Patients target: '.$patientsTarget.', appointments target: '.$appointmentsTarget."\n";

    // Point the app at a throwaway file-backed SQLite database.
    config(['database.connections.sqlite.database' => $tmpDb]);
    DB::purge('sqlite');
    DB::connection('sqlite')->reconnect();

    $start = hrtime(true);
    Artisan::call('migrate', ['--force' => true]);
    echo 'Migrate throwaway DB: '.rtoElapsed($start)."\n";

    // Admin (required by clinic:backup) + a pool of doctors.
    User::factory()->create(['role' => 'Admin', 'email' => 'rto-admin@example.test']);
    $doctors = User::factory()->count(8)->create(['role' => 'Doctor']);

    // Seed patients with deterministic unique emails (faker safeEmail() collides at scale).
    $start = hrtime(true);
    for ($i = 1; $i <= $patientsTarget; $i++) {
        Patient::factory()->create(['email' => 'timing-patient-'.$i.'@example.test']);
    }
    $patientIds = DB::table('patients')->pluck('id')->all();
    $doctorIds = $doctors->pluck('id')->all();

    // Appointments carry a UNIQUE (doctor_id, scheduled_at) constraint, so
    // schedule each doctor on sequential minutes — uniqueness by construction,
    // no retry loop, and the time spread stays realistic (each doctor booked
    // ~1/minute across the window).
    $statuses = ['scheduled', 'completed', 'cancelled', 'checked_in', 'no_show'];
    $now = now();
    $doctorCount = count($doctorIds);
    $baseTs = now()->subDays(7)->startOfDay()->timestamp;
    for ($offset = 0; $offset < $appointmentsTarget; $offset += 500) {
        $rows = [];
        $batch = min(500, $appointmentsTarget - $offset);
        for ($i = 0; $i < $batch; $i++) {
            $global = $offset + $i;
            $rows[] = [
                'patient_id' => $patientIds[array_rand($patientIds)],
                'doctor_id' => $doctorIds[$global % $doctorCount],
                'scheduled_at' => date('Y-m-d H:i:s', $baseTs + (int) floor($global / $doctorCount) * 60),
                'status' => $statuses[array_rand($statuses)],
                'reason' => 'Reason '.$global.' of appointment seed',
                'notes' => 'Notes '.$global,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('appointments')->insert($rows);
    }
    echo 'Seed '.$patientsTarget.' patients + '.$appointmentsTarget.' appointments: '.rtoElapsed($start)."\n";

    $dbSize = filesize($tmpDb);
    echo 'Seeded DB file size: '.number_format($dbSize)." bytes\n";

    // Generate a real backup through the real command.
    $start = hrtime(true);
    Artisan::call('clinic:backup');
    echo 'clinic:backup generation: '.rtoElapsed($start)."\n";

    $backup = SystemBackup::query()->where('status', SystemBackup::STATUS_COMPLETED)->firstOrFail();
    echo 'Backup record: #'.$backup->id.' '.$backup->filename.', '.number_format((int) $backup->file_size)." bytes\n";
    echo 'Raw attributes: '.json_encode($backup->getAttributes())."\n";
    echo 'Disk size: '.Storage::disk('local')->size($backup->file_path)." bytes\n";
    echo "clinic:backup output:\n".Artisan::output()."\n";

    echo 'Backup disk root: '.Storage::disk('local')->path('/')."\n";

    $countsBefore = [
        'patients' => DB::table('patients')->count(),
        'appointments' => DB::table('appointments')->count(),
    ];

    // ===== Phase timing: same primitives BackupService::restoreBackup() uses =====
    $phases = [];

    // 1. Locate + download (read archive bytes off the backup disk)
    $t = hrtime(true);
    $encryptedContent = Storage::disk('local')->get($backup->file_path);
    $encryptedFile = $tmpDir.'/backup.enc';
    file_put_contents($encryptedFile, $encryptedContent);
    $tmpFiles[] = $encryptedFile;
    $phases['locate_and_download'] = ['micros' => (hrtime(true) - $t) / 1000, 'note' => 'get() + temp write, local disk'];

    // 2. Decrypt
    $t = hrtime(true);
    $decryptedContent = Crypt::decryptString($encryptedContent);
    $compressedFile = $tmpDir.'/backup.gz';
    file_put_contents($compressedFile, $decryptedContent);
    $tmpFiles[] = $compressedFile;
    $phases['decrypt'] = ['micros' => (hrtime(true) - $t) / 1000, 'note' => 'Crypt::decryptString + temp write'];

    // 3. Verify checksum
    $t = hrtime(true);
    $actualChecksum = hash_file('sha256', $compressedFile);
    $verified = hash_equals((string) $backup->checksum_sha256, $actualChecksum);
    $phases['checksum_verify'] = ['micros' => (hrtime(true) - $t) / 1000, 'note' => 'hash_file sha256, '.($verified ? 'match' : 'MISMATCH')];

    // 4. Decompress
    $t = hrtime(true);
    $dumpFile = $tmpDir.'/backup.sql';
    $output = [];
    $rc = 0;
    exec('gzip -d -c '.escapeshellarg($compressedFile).' > '.escapeshellarg($dumpFile), $output, $rc);
    $tmpFiles[] = $dumpFile;
    $phases['decompress'] = ['micros' => (hrtime(true) - $t) / 1000, 'note' => 'gzip -d -c, rc='.$rc];

    // 5. Import (SQLite = file copy; MySQL would be `mysql < dump` at staging)
    //    Measured into a scratch SQLite file so the live database is untouched
    //    ahead of the end-to-end restore. The real import also .bak-copies the
    //    live database first (+1 copy) and then deletes the .bak.
    $t = hrtime(true);
    $importScratch = $tmpDir.'/import_scratch.sqlite';
    copy($dumpFile, $importScratch);
    $tmpFiles[] = $importScratch;
    $phases['import'] = ['micros' => (hrtime(true) - $t) / 1000, 'note' => 'SQLite file-copy into scratch DB (MySQL: staging G4)'];

    echo "\n-- Phase timings --\n";
    foreach ($phases as $name => $phase) {
        echo str_pad($name, 20).' '.rtoMs($phase['micros']).'  '.$phase['note']."\n";
    }

    // ===== Real end-to-end restore (the actual command path) =====
    // Re-seed a marker row so we can prove the restore actually replaces data.
    Patient::factory()->create(['email' => 'should-be-wiped@example.test']);
    $extraCount = DB::table('patients')->count();
    $expectedAfter = $countsBefore['patients'];

    $t = hrtime(true);
    $restoreExit = Artisan::call('clinic:restore', ['backupId' => (string) $backup->id, '--force' => true]);
    $e2eMicros = (hrtime(true) - $t) / 1000;
    echo 'End-to-end clinic:restore --force: '.rtoMs($e2eMicros).' (exit '.$restoreExit.")\n";
    echo "clinic:restore output:\n".Artisan::output()."\n";

    // Diagnostic: can a SECOND restore run against the just-restored database?
    // The restored snapshot carries the backup record as it was at snapshot
    // time (status=pending, file_path=backups/<ts>.sql.gz.enc), so a second
    // restore is expected to fail on the "not completed" check.
    $secondExit = Artisan::call('clinic:restore', ['backupId' => (string) $backup->id, '--force' => true]);
    echo 'Second consecutive restore exit: '.$secondExit."\n";
    echo "Second restore output:\n".Artisan::output()."\n";

    $countsAfter = [
        'patients' => DB::table('patients')->count(),
        'appointments' => DB::table('appointments')->count(),
    ];
    $ok = $countsAfter === $countsBefore && $extraCount === ($expectedAfter + 1)
        && DB::table('patients')->where('email', 'should-be-wiped@example.test')->doesntExist();
    echo 'Restore integrity: '.($ok ? 'pass' : 'FAIL')
        .' (before '.json_encode($countsBefore).', after '.json_encode($countsAfter).")\n";

    echo "\n== SUMMARY ==\n";
    foreach ($phases as $name => $phase) {
        echo str_pad($name, 20).rtoMs($phase['micros'])."\n";
    }
    echo str_pad('e2e_clinic_restore', 20).rtoMs($e2eMicros)."\n";
    echo str_pad('second_restore_exit', 20).' '.$secondExit."\n";
    echo str_pad('seeded_db_size_bytes', 20).' '.$dbSize."\n";
    echo str_pad('backup_size_bytes', 20).' '.(int) $backup->file_size."\n";
    echo str_pad('integrity', 20).($ok ? 'pass' : 'FAIL')."\n";

    rtoCleanup($tmpDb, $tmpDir, $tmpFiles, $backup);

    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "\nFATAL: ".$e->getMessage()."\n".$e->getTraceAsString()."\n");
    if (getenv('KEEP_EVIDENCE')) {
        fwrite(STDERR, 'Evidence kept: tmpDb='.$tmpDb.', tmpDir='.$tmpDir
            .', backupPath='.($backup?->file_path ?? 'none')."\n");
    } else {
        rtoCleanup($tmpDb, $tmpDir, $tmpFiles, $backup);
    }

    exit(1);
}
