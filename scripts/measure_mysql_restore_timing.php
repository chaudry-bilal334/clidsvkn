<?php

/**
 * RTO timing measurement for the mysqldump/mysql restore path.
 *
 * G4 staging counterpart of scripts/measure_restore_timing.php (that script's
 * SQLite copy-import is only a lower bound). This seeds a realistic-sized
 * dataset into a THROWAWAY MySQL database, generates a real backup through the
 * real `clinic:backup` command (which shells out to `mysqldump
 * --single-transaction`), times each restore phase with the same primitives
 * BackupService::restoreBackup() uses (locate/download, decrypt, checksum
 * verify, decompress, then a real `mysql < dump` import), and finally times
 * the real `clinic:restore --force` end-to-end — the number the runbook §6
 * table needs.
 *
 * PREREQUISITES (see docs/STAGING_VALIDATION_CHECKLIST.md §4; run via
 * scripts/mysql_staging_run.sh which sets these up):
 *   - .env points at a reachable MySQL 8.x connection (DB_CONNECTION=mysql)
 *   - `mysqldump` and `mysql` client binaries on PATH, with a user that may
 *     CREATE/DROP databases (the app user usually needs this grant; if the
 *     grant is missing the import-phase measurement is skipped with a note,
 *     and the end-to-end restore still exercises the real mysql import)
 *   - PHP pdo_mysql extension loaded
 *
 * SAFETY: never touches the real application database. Two throwaway schemas
 * `clinic_rto_timing_<token>` (seeded; backup + end-to-end restore happen
 * here) and `clinic_rto_import_<token>` (scratch target for the raw import
 * phase) are created at the start and dropped at the end. The dev database
 * file / configured database is never written.
 *
 * Run from backend_laravel/ (standalone PHP, boots Laravel directly):
 *
 *   php scripts/measure_mysql_restore_timing.php
 *
 * Optional sizing via env (same names as the SQLite script):
 *   RTO_PATIENTS=5000 RTO_APPOINTMENTS=30000 php scripts/measure_mysql_restore_timing.php
 *
 * Exit code 0 = restore integrity verified; 1 = any failure.
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

$driver = config('database.default');
if ($driver !== 'mysql') {
    fwrite(STDERR, "Refusing to run: database.default is '{$driver}', not 'mysql'.\n");
    fwrite(STDERR, "Point .env at MySQL first (or run scripts/mysql_staging_run.sh).\n");
    exit(1);
}

foreach (['mysqldump', 'mysql'] as $binary) {
    exec('command -v '.escapeshellarg($binary), $_, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "Prerequisite missing: `{$binary}` is not on PATH.\n");
        exit(1);
    }
}

$conn = config('database.connections.mysql');
$host = $conn['host'] ?? '127.0.0.1';
$port = $conn['port'] ?? '3306';
$username = $conn['username'];
$password = $conn['password'] ?? '';
$token = uniqid();
$seedDb = 'clinic_rto_timing_'.$token;
$importDb = 'clinic_rto_import_'.$token;

function rtoMs(float $micros): string
{
    return sprintf('%.1f ms', $micros / 1000);
}

function rtoElapsed(float $startNs): string
{
    return rtoMs((hrtime(true) - $startNs) / 1000);
}

/** Server-level PDO connect (no database selected) for CREATE/DROP DATABASE. */
function rtoServerPdo(string $host, string $port, string $username, string $password): PDO
{
    return new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_TIMEOUT => 5, PDO::ERRMODE_EXCEPTION => true]
    );
}

/** Best-effort cleanup: drop both throwaway schemas + delete temp artifacts. */
function rtoCleanup(string $seedDb, string $importDb, string $tmpDir, array $tmpFiles, ?SystemBackup $backup = null, ?PDO $pdo = null, bool $dropDatabases = true): void
{
    if ($pdo !== null && $dropDatabases) {
        try {
            $pdo->exec('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $seedDb).'`');
            $pdo->exec('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $importDb).'`');
        } catch (Throwable) {
            // best effort
        }
    }

    foreach ($tmpFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($tmpDir);

    if ($backup !== null) {
        try {
            Storage::disk('local')->delete($backup->file_path);
        } catch (Throwable) {
            // best effort
        }
    }
}

$tmpDir = sys_get_temp_dir().'/rto_mysql_'.uniqid();
mkdir($tmpDir, 0777, true);
$tmpFiles = [];
$backup = null;
$pdo = null;
/** @var array<string, array{micros: float, note: string}> $phases */
$phases = [];

try {
    echo "== RTO timing run (MySQL/mysqldump) ==\n";
    echo 'Patients target: '.$patientsTarget.', appointments target: '.$appointmentsTarget."\n";
    echo 'Target connection: '.$username.'@'.$host.':'.$port."\n";

    // Create the throwaway schemas. The import-scratch schema is optional
    // (needs CREATE DATABASE on the app user); the seed schema is required.
    try {
        $pdo = rtoServerPdo($host, $port, $username, $password);
        $pdo->exec('CREATE DATABASE `'.str_replace('`', '``', $seedDb).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $canCreate = true;
        try {
            $pdo->exec('CREATE DATABASE `'.str_replace('`', '``', $importDb).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable) {
            $canCreate = false;
            fwrite(STDERR, "Note: could not create scratch import schema ({$importDb}) — raw import-phase timing will be skipped; the end-to-end restore below still exercises the real `mysql < dump` import.\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "FATAL: cannot connect to MySQL ({$host}:{$port}) as '{$username}': ".$e->getMessage()."\n");
        exit(1);
    }

    // Point the app at the throwaway seed database (runtime only — .env and
    // the real database are untouched).
    config(['database.connections.mysql.database' => $seedDb]);
    DB::purge('mysql');
    DB::connection('mysql')->reconnect();

    $start = hrtime(true);
    Artisan::call('migrate', ['--force' => true]);
    echo 'Migrate throwaway MySQL DB: '.rtoElapsed($start)."\n";

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
    // schedule each doctor on sequential minutes — uniqueness by construction.
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

    $sizeRow = DB::selectOne(
        'SELECT ROUND((SUM(data_length) + SUM(index_length)) / 1024 / 1024, 1) AS mb
         FROM information_schema.TABLES WHERE table_schema = ?',
        [$seedDb]
    );
    echo 'Seeded DB size (data+index): '.($sizeRow->mb ?? 0)." MB\n";

    // Generate a real backup through the real command (mysqldump path).
    $start = hrtime(true);
    Artisan::call('clinic:backup');
    echo 'clinic:backup generation: '.rtoElapsed($start)."\n";

    $backup = SystemBackup::query()->where('status', SystemBackup::STATUS_COMPLETED)->firstOrFail();
    echo 'Backup record: #'.$backup->id.' '.$backup->filename.', '.number_format((int) $backup->file_size)." bytes\n";
    echo "clinic:backup output:\n".Artisan::output()."\n";

    $countsBefore = [
        'patients' => DB::table('patients')->count(),
        'appointments' => DB::table('appointments')->count(),
    ];

    // ===== Phase timing: same primitives BackupService::restoreBackup() uses =====
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
    $decompressOutput = [];
    $decompressReturnCode = 0;
    exec('gzip -d -c '.escapeshellarg($compressedFile).' > '.escapeshellarg($dumpFile), $decompressOutput, $decompressReturnCode);
    $tmpFiles[] = $dumpFile;
    $phases['decompress'] = ['micros' => (hrtime(true) - $t) / 1000, 'note' => 'gzip -d -c, rc='.$decompressReturnCode];

    // 5. Import — the actual `mysql < dump` traffic, into the scratch schema
    //    (same command shape as BackupService::importDatabase()).
    $importCommand = "mysql -h {$host} -P {$port} -u {$username} ";
    if ($password) {
        $importCommand .= "-p'{$password}' ";
    }
    $importCommand .= $importDb.' < '.escapeshellarg($dumpFile);

    $t = hrtime(true);
    if ($canCreate) {
        $importOutput = [];
        $importReturnCode = 0;
        exec($importCommand, $importOutput, $importReturnCode);
        $note = 'mysql < dump into scratch schema, rc='.$importReturnCode;
        if ($importReturnCode !== 0) {
            $note .= ' ('.implode('; ', $importOutput).')';
        }
    } else {
        $note = 'skipped (scratch schema not creatable — e2e restore below still measures real import)';
    }
    $phases['import'] = ['micros' => (hrtime(true) - $t) / 1000, 'note' => $note];

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

    // Diagnostic: a SECOND restore of the same row is expected to fail
    // (snapshot re-hydrates the row as status=pending) — same known
    // limitation as SQLite; runbook §3 documents the workaround.
    $secondExit = Artisan::call('clinic:restore', ['backupId' => (string) $backup->id, '--force' => true]);
    echo 'Second consecutive restore exit: '.$secondExit."\n";

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
    echo str_pad('seeded_db_size_mb', 20).' '.($sizeRow->mb ?? 0)."\n";
    echo str_pad('backup_size_bytes', 20).' '.(int) $backup->file_size."\n";
    echo str_pad('integrity', 20).($ok ? 'pass' : 'FAIL')."\n";

    // Paste-ready block for docs/DISASTER_RECOVERY_RUNBOOK.md §6.
    echo "\n== RUNBOOK §6 PASTE-READY TABLE ==\n";
    echo "| Phase | Wall-clock (MySQL) |\n|---|---|\n";
    echo '| Dataset | '.number_format($patientsTarget).' patients + '.number_format($appointmentsTarget)
        .' appointments, '.($sizeRow->mb ?? 0).' MB MySQL (data+index) |'."\n";
    echo '| Encrypted archive on disk | '.number_format((int) $backup->file_size).' bytes |'."\n";
    foreach ($phases as $name => $phase) {
        $clean = str_replace('_', ' ', ucfirst($name));
        echo '| '.$clean.' | **'.rtoMs($phase['micros']).'** |'."\n";
    }
    echo '| **End-to-end `clinic:restore --force`** | **'.rtoMs($e2eMicros).' (exit '.$restoreExit.', integrity '
        .($ok ? 'pass' : 'FAIL').')** |'."\n";
    echo '| `clinic:backup` generation (for context) | '.rtoElapsed($start).' |'."\n";

    rtoCleanup($seedDb, $importDb, $tmpDir, $tmpFiles, $backup, $pdo);

    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "\nFATAL: ".$e->getMessage()."\n".$e->getTraceAsString()."\n");
    if (getenv('KEEP_EVIDENCE')) {
        fwrite(STDERR, 'Evidence kept: seedDb='.$seedDb.', importDb='.$importDb.', tmpDir='.$tmpDir
            .', backupPath='.($backup?->file_path ?? 'none')."\n");
    } else {
        rtoCleanup($seedDb, $importDb, $tmpDir, $tmpFiles, $backup, $pdo);
    }

    exit(1);
}
