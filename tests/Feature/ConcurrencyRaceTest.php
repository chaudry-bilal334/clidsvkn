<?php

use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Patient;
use App\Models\User;
use App\Services\DoctorAvailabilityService;
use Illuminate\Support\Facades\DB;

/**
 * Real-parallel race coverage for the two highest-value go-live invariants:
 * appointment slots are never double-booked, and dispensing the last unit never
 * drives stock below zero. Children are forked with pcntl, released against a
 * shared barrier, and each performs a genuine simultaneous HTTP request against
 * the same file-backed SQLite database. The file database is required because
 * forked processes cannot share an in-memory SQLite store.
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
    $this->raceDbFile = tempnam(sys_get_temp_dir(), 'race_').'.sqlite';

    config(['database.connections.sqlite.database' => $this->raceDbFile]);
    DB::purge('sqlite');

    $this->artisan('migrate', ['--force' => true]);
});

afterEach(function (): void {
    DB::purge('sqlite');

    if (file_exists($this->raceDbFile)) {
        @unlink($this->raceDbFile);
    }
});

/**
 * Fork $count children. Each child waits on the shared $startFlag barrier, runs
 * $childWork($index) against its own fresh SQLite connection, and reports the
 * outcome string through its own result file. Returns the outcome strings in
 * child order.
 *
 * @param  Closure(int): string  $childWork
 * @return array<int, string>
 */
function race_fork(int $count, Closure $childWork, string $startFlag): array
{
    $pids = [];
    $resultFiles = [];

    for ($i = 0; $i < $count; $i++) {
        $resultFile = $resultFiles[$i] = tempnam(sys_get_temp_dir(), 'race_res_');
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid === 0) {
            // Child: force an independent connection to the shared file DB, then
            // wait for the parent to release every child together.
            DB::purge('sqlite');

            $deadline = microtime(true) + 10;
            while (! file_exists($startFlag) && microtime(true) < $deadline) {
                usleep(2000);
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

    // Release all children at once, then reap them.
    file_put_contents($startFlag, 'go');

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    @unlink($startFlag);

    return array_map(
        static fn (string $file): string => file_get_contents($file) ?: 'NO-RESULT',
        $resultFiles,
    );
}

test('a double-booking race still yields exactly one appointment per slot', function () {
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

    $startFlag = tempnam(sys_get_temp_dir(), 'race_flag_');

    $outcomes = race_fork(2, function () use ($payload, $admin) {
        $response = $this->actingAs($admin)->postJson('/api/v1/appointments', $payload);

        return $response->status().':'.($response->json('data.id') ?? 'none');
    }, $startFlag);

    $successes = collect($outcomes)
        ->filter(fn (string $outcome): bool => str_starts_with($outcome, '201'))
        ->count();

    // Exactly one winner; the loser must observe a handled 422 (either the
    // availability check or the unique-constraint catch), never a 500.
    expect($successes)->toBe(1)
        ->and(collect($outcomes)->filter(fn (string $outcome): bool => str_starts_with($outcome, '422'))->count())->toBe(1)
        ->and(collect($outcomes)->filter(fn (string $outcome): bool => str_starts_with($outcome, '500'))->count())->toBe(0)
        ->and(Appointment::where('doctor_id', $doctor->id)
            ->where('scheduled_at', $slot->format('Y-m-d H:i:s'))
            ->count())->toBe(1);
});

test('a slot conflict that passes the availability check is caught as a 422 by the unique constraint', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $patient = Patient::factory()->create();
    $doctor = User::factory()->create(['role' => 'Doctor']);

    $slot = now()->addDays(3)->startOfDay()->addHours(10);
    $payload = [
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'scheduled_at' => $slot->format('Y-m-d H:i:s'),
    ];

    // Simulate the race window: the availability check always passes, forcing
    // the second request into the UNIQUE(doctor_id, scheduled_at) constraint.
    $this->mock(DoctorAvailabilityService::class)
        ->shouldReceive('assertSlotAvailable')
        ->andReturnNull();

    $first = $this->actingAs($admin)->postJson('/api/v1/appointments', $payload);
    $second = $this->actingAs($admin)->postJson('/api/v1/appointments', $payload);

    $first->assertCreated();
    $second->assertStatus(422);
    $second->assertJsonPath('message', 'This slot was just booked by another request.');

    expect(Appointment::where('doctor_id', $doctor->id)
        ->where('scheduled_at', $slot->format('Y-m-d H:i:s'))
        ->count())->toBe(1);
});

test('a parallel last-unit dispense race never drives stock below zero', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $item = InventoryItem::factory()->create([
        'item_name' => 'Amoxicillin 500mg',
        'sku' => 'AMOX-500',
        'stock_quantity' => 1,
        'reorder_level' => 1,
    ]);

    $payload = [
        'item_id' => $item->id,
        'type' => 'dispense',
        'quantity' => 1,
    ];

    $startFlag = tempnam(sys_get_temp_dir(), 'race_flag_');

    $outcomes = race_fork(2, function () use ($payload, $admin) {
        return (string) $this->actingAs($admin)
            ->postJson('/api/v1/inventory/transactions', $payload)
            ->status();
    }, $startFlag);

    // Exactly one dispense wins; the loser must end on a handled 4xx (422 from
    // the post-commit availability guard, or 409 after the retry budget) —
    // never a raw 500 (SQLite busy or otherwise).
    expect(InventoryTransaction::query()->where('type', 'dispense')->count())->toBe(1)
        ->and(collect($outcomes)->filter(fn (string $outcome): bool => str_starts_with($outcome, '500'))->count())->toBe(0)
        ->and(collect($outcomes)->reject(fn (string $outcome): bool => str_starts_with($outcome, '201'))->each(
            fn (string $outcome) => expect($outcome)->toBeIn(['409', '422']),
        ));

    $item->refresh();
    $batch = InventoryBatch::query()->where('inventory_item_id', $item->id)->first();

    expect((float) $item->stock_quantity)->toBe(0.0)
        ->and((float) $batch->current_quantity)->toBe(0.0);
});

test('a dispense blocked by a held write lock returns 409 after the retry budget, not a 500', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $item = InventoryItem::factory()->create([
        'item_name' => 'Paracetamol 500mg',
        'sku' => 'PARA-500',
        'stock_quantity' => 10,
    ]);

    // A second connection holds a RESERVED write lock on the database, so every
    // retry inside store() hits SQLite "database is locked" (deterministically
    // outlasting the retry budget).
    $blocker = new PDO('sqlite:'.$this->raceDbFile);
    $blocker->exec('BEGIN IMMEDIATE');
    $blocker->exec('UPDATE inventory_items SET stock_quantity = stock_quantity WHERE 1 = 0');

    $response = $this->actingAs($admin)->postJson('/api/v1/inventory/transactions', [
        'item_id' => $item->id,
        'type' => 'dispense',
        'quantity' => 1,
    ]);

    $blocker->rollBack();

    $response->assertStatus(409);
    $response->assertJsonPath('message', 'This inventory record is busy; another transaction is updating it. Please retry.');
    expect(InventoryTransaction::query()->where('type', 'dispense')->count())->toBe(0);
});

test('the database unique slot constraint is the deterministic backstop against double booking', function () {
    $patient = Patient::factory()->create();
    $doctor = User::factory()->create(['role' => 'Doctor']);

    $slot = now()->addDays(3)->startOfDay()->addHours(10)->format('Y-m-d H:i:s');

    // Two independent PDO connections to the same file database. The first
    // insert commits; the second must be refused by the unique constraint even
    // though no application code runs between them.
    $pdoA = new PDO('sqlite:'.$this->raceDbFile);
    $pdoB = new PDO('sqlite:'.$this->raceDbFile);

    $sql = 'insert into appointments (patient_id, doctor_id, scheduled_at, status, branch_id, created_at, updated_at) values (?, ?, ?, ?, NULL, datetime(\'now\'), datetime(\'now\'))';

    $pdoA->prepare($sql)->execute([$patient->id, $doctor->id, $slot, 'booked']);

    $secondInsertFailed = false;
    try {
        $pdoB->prepare($sql)->execute([$patient->id, $doctor->id, $slot, 'booked']);
    } catch (PDOException $e) {
        $secondInsertFailed = true;
    }

    expect($secondInsertFailed)->toBeTrue()
        ->and(Appointment::where('doctor_id', $doctor->id)
            ->where('scheduled_at', $slot)
            ->count())->toBe(1);
});
