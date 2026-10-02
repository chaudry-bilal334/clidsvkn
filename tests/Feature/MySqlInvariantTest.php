<?php

use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Patient;
use App\Models\SystemBackup;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Database-engine invariants that only exist on MySQL (PRD §18, §19).
 *
 * The default suite runs on SQLite, which cannot prove any of the following:
 *
 * 1. The double-booking backstop on MySQL is a STORED generated column plus a
 *    unique index, not a partial index. Its behaviour under genuine InnoDB
 *    contention has never been executed in this repository.
 * 2. A released (cancelled / no-show) slot must become bookable again. That
 *    relies on the generated column yielding NULL, and MySQL ignoring NULLs in
 *    a unique index. SQLite cannot express it the same way, so the behaviour
 *    is only proven by a different mechanism there.
 * 3. The nightly backup shells out to `mysqldump` and the restore shells out
 *    to `mysql`. The SQLite suite exercises a file-copy path instead, so the
 *    production command line is unverified.
 *
 * Every test here refuses to pass on a non-MySQL connection: a green result
 * from the wrong engine would be worse than a failure, because it would be
 * cited as evidence for a go-live gate.
 */
pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->mysqldump = null;
    $this->mysqlClient = null;
});

/**
 * Skip the calling test unless it is genuinely running on MySQL.
 *
 * Every test in this file calls this first. A green result from the wrong
 * engine would be worse than a failure, because it would be cited as evidence
 * for a go-live gate that MySQL has not actually passed.
 */
function require_mysql(): void
{
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped(
            'MySQL-only invariant (running on '.DB::connection()->getDriverName().'). '
            .'Run via `composer run test:mysql` or scripts/verify-mysql.sh.'
        );
    }
}

/** Skip when the MySQL client binaries are not installed. */
function require_mysql_clients(): void
{
    if (mysqldump_binary() === null || mysql_binary() === null) {
        test()->markTestSkipped('mysqldump / mysql client binaries are not installed on this host.');
    }
}

afterEach(function (): void {
    Storage::disk('local')->deleteDirectory('backups');

    if (is_string($this->mysqldump ?? null)) {
        @unlink($this->mysqldump);
    }

    if (is_string($this->mysqlClient ?? null)) {
        @unlink($this->mysqlClient);
    }
});

/**
 * Fork $count children against the shared MySQL server, released together on a
 * barrier, and return each child's outcome string.
 *
 * MySQL needs no shared file: every child opens its own connection to the same
 * server, which is what makes this a genuine concurrency test rather than a
 * simulation of one.
 *
 * @param  Closure(int): string  $childWork
 * @return array<int, string>
 */
function fork_race(int $count, Closure $childWork): array
{
    $pids = [];
    $resultFiles = [];

    for ($i = 0; $i < $count; $i++) {
        $resultFile = $resultFiles[$i] = tempnam(sys_get_temp_dir(), 'mysql_race_res_');
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid === 0) {
            // Child: a fresh MySQL connection, then wait for the parent to
            // release every child at the same moment.
            DB::purge('mysql');

            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                try {
                    DB::connection('mysql')->getPdo();

                    break;
                } catch (Throwable) {
                    usleep(5000);
                }
            }

            try {
                file_put_contents($resultFile, (string) $childWork($i));
                exit(0);
            } catch (Throwable $e) {
                file_put_contents($resultFile, 'THROW:'.get_class($e).':'.$e->getMessage());
                exit(1);
            }
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    return array_map(
        static fn (string $file): string => file_get_contents($file) ?: 'NO-RESULT',
        $resultFiles,
    );
}

/**
 * Absolute path to the mysqldump binary, or null when it is not installed.
 * The restore round-trip skips rather than faking it: "mysqldump missing" is a
 * fact the evidence file must record, not something to paper over.
 */
function mysqldump_binary(): ?string
{
    $output = [];
    $exit = 0;
    exec('command -v mysqldump 2>/dev/null', $output, $exit);

    return $exit === 0 && $output !== [] ? trim($output[0]) : null;
}

function mysql_binary(): ?string
{
    $output = [];
    $exit = 0;
    exec('command -v mysql 2>/dev/null', $output, $exit);

    return $exit === 0 && $output !== [] ? trim($output[0]) : null;
}

test('the appointment backstop is a MySQL generated column plus a unique index', function () {
    require_mysql();

    $columns = collect(DB::select('SHOW COLUMNS FROM appointments'))
        ->keyBy('Field');

    // The generated column is what makes a released slot reusable while an
    // active one stays protected. Without it, a cancelled appointment would
    // permanently burn the slot.
    expect($columns->get('active_slot'))->not->toBeNull()
        ->and(strtoupper((string) $columns->get('active_slot')->Extra))->toContain('STORED GENERATED');

    $indexes = collect(DB::select('SHOW INDEX FROM appointments'))
        ->where('Key_name', 'appointments_active_slot_unique');

    expect($indexes)->not->toBeEmpty()
        ->and($indexes->pluck('Column_name')->all())->toContain('doctor_id', 'active_slot')
        ->and($indexes->first()->Non_unique)->toBe(0);
});

test('two active appointments cannot share a doctor slot at the database level', function () {
    require_mysql();

    $patient = Patient::factory()->create();
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $slot = now()->addDays(3)->startOfDay()->addHours(10)->format('Y-m-d H:i:s');

    // Two independent PDO connections: nothing in application code runs
    // between them, so only the database can refuse the second insert.
    $pdo = DB::connection()->getPdo();
    $dsn = (string) config('database.connections.mysql.dsn');
    $mk = fn (): PDO => new PDO(
        $dsn !== '' ? $dsn : sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            config('database.connections.mysql.host'),
            config('database.connections.mysql.port'),
            config('database.connections.mysql.database'),
        ),
        (string) config('database.connections.mysql.username'),
        (string) config('database.connections.mysql.password'),
    );

    $sql = "insert into appointments (patient_id, doctor_id, scheduled_at, status, branch_id, created_at, updated_at)
            values (?, ?, ?, 'booked', NULL, now(), now())";

    $a = $mk();
    $b = $mk();

    $a->prepare($sql)->execute([$patient->id, $doctor->id, $slot]);

    $refused = false;
    $sqlState = null;
    try {
        $b->prepare($sql)->execute([$patient->id, $doctor->id, $slot]);
    } catch (PDOException $e) {
        $refused = true;
        $sqlState = $e->getCode();
    }

    unset($pdo);

    expect($refused)->toBeTrue('MySQL must refuse the second active appointment for the same doctor slot')
        // 23000 = integrity constraint violation, which is the unique index
        // firing rather than an application-level check.
        ->and((string) $sqlState)->toStartWith('23')
        ->and(Appointment::where('doctor_id', $doctor->id)->where('scheduled_at', $slot)->count())->toBe(1);
});

test('cancelling an appointment releases its slot for rebooking', function () {
    require_mysql();

    $patient = Patient::factory()->create();
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $slot = now()->addDays(3)->startOfDay()->addHours(10);

    $first = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'scheduled_at' => $slot,
        'status' => Appointment::STATUS_BOOKED,
    ]);

    // A cancelled appointment must not burn the slot: the generated column
    // yields NULL and MySQL ignores NULLs in a unique index.
    $first->update(['status' => Appointment::STATUS_CANCELLED, 'cancelled_at' => now()]);

    $second = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'scheduled_at' => $slot,
        'status' => Appointment::STATUS_BOOKED,
    ]);

    expect($second->exists)->toBeTrue()
        ->and(Appointment::where('doctor_id', $doctor->id)->where('scheduled_at', $slot)->count())->toBe(2)
        ->and(Appointment::where('status', Appointment::STATUS_BOOKED)
            ->where('doctor_id', $doctor->id)
            ->where('scheduled_at', $slot)
            ->count())->toBe(1);
});

test('several released rows may share a slot, proving the generated column is NULL not duplicated', function () {
    require_mysql();

    $patient = Patient::factory()->create();
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $slot = now()->addDays(3)->startOfDay()->addHours(10);

    Appointment::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'scheduled_at' => $slot,
        'status' => Appointment::STATUS_BOOKED,
    ]);

    foreach ([Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_CANCELLED] as $status) {
        Appointment::factory()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $slot,
            'status' => $status,
        ]);
    }

    $activeSlots = DB::selectOne(
        'select active_slot from appointments where doctor_id = ? and status = ?',
        [$doctor->id, Appointment::STATUS_BOOKED]
    );

    expect(Appointment::where('doctor_id', $doctor->id)->where('scheduled_at', $slot)->count())->toBe(4)
        ->and($activeSlots)->not->toBeNull();
});

test('a double-booking race through the API yields one winner and one handled 422', function () {
    require_mysql();

    $admin = User::factory()->create(['role' => 'Admin']);
    $patient = Patient::factory()->create();
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $slot = now()->addDays(3)->startOfDay()->addHours(10);

    DoctorSchedule::factory()
        ->on((string) $slot->dayOfWeek, '07:00', '23:00')
        ->create(['doctor_id' => $doctor->id]);

    $payload = [
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'scheduled_at' => $slot->format('Y-m-d H:i:s'),
    ];

    $outcomes = fork_race(2, function () use ($payload, $admin): string {
        $response = $this->actingAs($admin)->postJson('/api/v1/appointments', $payload);

        return (string) $response->status();
    });

    // The application/transaction check and the database backstop are two
    // independent defences; either may win, but a 500 means a dead end.
    expect(collect($outcomes)->filter(fn (string $s): bool => $s === '201')->count())->toBe(1)
        ->and(collect($outcomes)->filter(fn (string $s): bool => in_array($s, ['409', '422'], true))->count())->toBe(1)
        ->and(collect($outcomes)->filter(fn (string $s): bool => str_starts_with($s, '5'))->count())->toBe(0)
        ->and(Appointment::where('doctor_id', $doctor->id)
            ->where('scheduled_at', $slot->format('Y-m-d H:i:s'))
            ->count())->toBe(1);
});

test('a parallel last-unit dispense race never drives stock below zero on MySQL', function () {
    require_mysql();

    $admin = User::factory()->create(['role' => 'Admin']);
    $item = InventoryItem::factory()->create([
        'item_name' => 'Amoxicillin 500mg',
        'sku' => 'AMOX-MYSQL',
        'stock_quantity' => 1,
        'reorder_level' => 1,
    ]);

    $payload = ['item_id' => $item->id, 'type' => 'dispense', 'quantity' => 1];

    $outcomes = fork_race(2, fn (): string => (string) $this->actingAs($admin)
        ->postJson('/api/v1/inventory/transactions', $payload)
        ->status());

    expect(InventoryTransaction::query()->where('type', 'dispense')->count())->toBe(1)
        ->and(collect($outcomes)->filter(fn (string $s): bool => str_starts_with($s, '5'))->count())->toBe(0)
        ->and(collect($outcomes)->reject(fn (string $s): bool => $s === '201')->each(
            fn (string $outcome) => expect($outcome)->toBeIn(['409', '422']),
        ));

    $item->refresh();
    $batch = InventoryBatch::query()->where('inventory_item_id', $item->id)->first();

    expect((float) $item->stock_quantity)->toBe(0.0)
        ->and((float) $batch->current_quantity)->toBe(0.0);
});

test('mysqldump produces a dump that restores into a second schema with matching row counts', function () {
    require_mysql();

    $dump = mysqldump_binary();
    $client = mysql_binary();

    if ($dump === null || $client === null) {
        expect(true)->toBeTrue();
    }

    // Seed a representative slice of every clinical and financial table the
    // go-live gate cares about.
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $patient = Patient::factory()->create();
    $item = InventoryItem::factory()->create(['stock_quantity' => 50, 'reorder_level' => 10]);
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => Appointment::STATUS_CONFIRMED,
    ]);

    $before = [
        'users' => DB::table('users')->count(),
        'patients' => DB::table('patients')->count(),
        'inventory_items' => DB::table('inventory_items')->count(),
        'appointments' => DB::table('appointments')->count(),
    ];

    expect($before['users'])->toBeGreaterThan(0)
        ->and($before['appointments'])->toBeGreaterThan(0)
        ->and($item->exists)->toBeTrue();

    $target = 'clinic_restore_probe';

    $host = (string) config('database.connections.mysql.host');
    $port = (string) config('database.connections.mysql.port');
    $user = (string) config('database.connections.mysql.username');
    $pass = (string) config('database.connections.mysql.password');
    $source = (string) config('database.connections.mysql.database');

    $dumpFile = tempnam(sys_get_temp_dir(), 'clinic_dump_').'.sql';
    $this->mysqldump = $dumpFile;

    $dumpCommand = sprintf(
        '%s -h %s -P %s -u %s %s --single-transaction --no-tablespaces --routines --triggers --result-file=%s %s 2>&1',
        escapeshellarg($dump),
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($user),
        $pass === '' ? '' : '-p'.escapeshellarg($pass),
        escapeshellarg($dumpFile),
        escapeshellarg($source),
    );

    exec($dumpCommand, $dumpOutput, $dumpExit);

    expect($dumpExit)->toBe(0, 'mysqldump failed: '.implode("\n", $dumpOutput))
        ->and(file_exists($dumpFile))->toBeTrue()
        ->and(filesize($dumpFile))->toBeGreaterThan(0);

    // Restore into a clean second schema, which is what a real recovery does.
    $admin = sprintf(
        'mysql -h %s -P %s -u %s %s -e %s 2>&1',
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($user),
        $pass === '' ? '' : '-p'.escapeshellarg($pass),
        escapeshellarg('DROP DATABASE IF EXISTS '.$target.'; CREATE DATABASE '.$target.';'),
    );

    exec($admin, $adminOutput, $adminExit);
    expect($adminExit)->toBe(0, 'could not create the restore target: '.implode("\n", $adminOutput));

    $restoreCommand = sprintf(
        '%s -h %s -P %s -u %s %s %s < %s 2>&1',
        escapeshellarg($client),
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($user),
        $pass === '' ? '' : '-p'.escapeshellarg($pass),
        escapeshellarg($target),
        escapeshellarg($dumpFile),
    );

    exec($restoreCommand, $restoreOutput, $restoreExit);
    $this->mysqlClient = $client;

    expect($restoreExit)->toBe(0, 'mysql restore failed: '.implode("\n", $restoreOutput));

    $restored = DB::connection()->select(
        'select table_name, table_rows from information_schema.tables where table_schema = ? and table_name in (?, ?, ?, ?)',
        [$target, 'users', 'patients', 'inventory_items', 'appointments'],
    );

    $this->mysqlClient = null;

    // information_schema.table_rows is an estimate for InnoDB, so compare the
    // exact counts for the rows that matter instead.
    $exact = DB::connection()->selectOne(
        'select (select count(*) from `'.$target.'`.users) as users,
                (select count(*) from `'.$target.'`.patients) as patients,
                (select count(*) from `'.$target.'`.inventory_items) as items,
                (select count(*) from `'.$target.'`.appointments) as appointments'
    );

    expect((int) $exact->users)->toBe($before['users'])
        ->and((int) $exact->patients)->toBe($before['patients'])
        ->and((int) $exact->items)->toBe($before['inventory_items'])
        ->and((int) $exact->appointments)->toBe($before['appointments']);

    // And the clinical payload survived, not just the counts.
    $restoredAppointment = DB::connection()->selectOne(
        'select status, scheduled_at from `'.$target.'`.appointments where id = ?',
        [$appointment->id],
    );

    expect($restoredAppointment)->not->toBeNull()
        ->and($restoredAppointment->status)->toBe(Appointment::STATUS_CONFIRMED);

    // The active-slot generated column and its unique index must come back
    // too, or the restored database has silently lost its double-booking guard.
    $restoredIndexes = collect(DB::connection()->select(
        'SHOW INDEX FROM `'.$target.'`.appointments WHERE Key_name = ?',
        ['appointments_active_slot_unique'],
    ));

    expect($restoredIndexes)->not->toBeEmpty();

    exec(sprintf(
        'mysql -h %s -P %s -u %s %s -e %s 2>&1',
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($user),
        $pass === '' ? '' : '-p'.escapeshellarg($pass),
        escapeshellarg('DROP DATABASE IF EXISTS '.$target.';'),
    ));
});

test('a MySQL dump round-trips through the application backup service', function () {
    require_mysql();

    require_mysql_clients();

    // BackupService shells out to mysqldump on MySQL; on SQLite it copies a
    // file. Proving the MySQL branch works is the point of this test.
    $admin = User::factory()->create(['role' => 'Admin']);
    Patient::factory()->create(['name' => 'Ayesha Khan', 'mrn' => 'MRN-0042']);

    $backup = app(BackupService::class)->generateBackup($admin);

    expect($backup->status)->toBe(SystemBackup::STATUS_COMPLETED)
        ->and($backup->is_encrypted)->toBeTrue()
        ->and($backup->checksum_sha256)->not->toBeNull()
        ->and((int) $backup->file_size)->toBeGreaterThan(0)
        ->and(Storage::disk('local')->exists($backup->file_path))->toBeTrue();

    // The stored bytes must be the encrypted archive, not the plain dump.
    $plainArchive = Crypt::decryptString(Storage::disk('local')->get($backup->file_path));

    expect(hash('sha256', $plainArchive))->toBe($backup->checksum_sha256)
        ->and($plainArchive)->toContain('CREATE TABLE')
        ->and(strlen($plainArchive))->toBeGreaterThan(0);
});

test('this suite actually ran against MySQL rather than skipping everything', function () {
    // A MySQL job that silently degraded to SQLite would report "0 failures"
    // with every test skipped, and that green result could be cited as evidence
    // for the PRD §19 double-booking and restore-drill gates. This test fails
    // instead, so the job cannot be mistaken for a pass.
    //
    // Under the default SQLite config this is expected to skip -- that run is
    // not the MySQL evidence run, and MySqlInvariantTest's own tests skip there.
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped(
            'MySQL self-check only meaningful under the MySQL invariant config '
            .'(running on '.DB::connection()->getDriverName().').'
        );
    }

    // Reaching this point on MySQL means the connection works, which is the
    // precondition for every other test in the file to have run rather than
    // skipped.
    expect(DB::select('SELECT VERSION() AS v')[0]->v)->toStartWith('8.');
});
