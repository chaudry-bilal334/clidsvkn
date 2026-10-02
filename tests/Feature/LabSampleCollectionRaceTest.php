<?php

use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Real-parallel race coverage for sample collection. Two simultaneous
 * collect-sample calls on the same order must result in exactly one success.
 *
 * Children are forked with pcntl and released against a shared barrier. A
 * file-backed SQLite database is required because forked processes cannot
 * share an in-memory SQLite store (same pattern as ConcurrencyRaceTest).
 *
 * This file deliberately does NOT use RefreshDatabase: switching the default
 * connection to a throwaway file DB conflicts with RefreshDatabase's
 * in-memory migration tracking and pollutes every subsequent test. The file
 * DB is created and migrated here instead.
 */
beforeEach(function (): void {
    $this->raceDbFile = tempnam(sys_get_temp_dir(), 'lsc_race_').'.sqlite';

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

test('two simultaneous collect-sample calls result in exactly one success', function () {
    $actor = User::factory()->create(['role' => 'Doctor']);
    $order = LabOrder::factory()->create([
        'patient_id' => Patient::factory()->create()->id,
        'test_name' => 'Complete Blood Count',
        'status' => LabOrder::STATUS_PENDING,
        'result' => null,
        'verification_state' => LabOrder::VERIFICATION_PENDING,
    ]);

    // Ensure the forked children see a fresh view of the database file.
    clearstatcache();

    $startFlag = tempnam(sys_get_temp_dir(), 'lsc_flag_');

    $outcomes = race_fork(2, function () use ($order, $actor) {
        return (string) $this->actingAs($actor)
            ->postJson("/api/v1/lab-orders/{$order->id}/collect-sample", ['sample_identifier' => 'SAMPLE-RACE'])
            ->status();
    }, $startFlag);

    $successes = collect($outcomes)->filter(fn (string $outcome): bool => $outcome === '200');

    expect($successes)->toHaveCount(1)
        ->and($order->refresh()->status)->toBe(LabOrder::STATUS_SAMPLE_COLLECTED)
        ->and($order->refresh()->sample_collected_by)->toBe($actor->id);
});
