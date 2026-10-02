<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

test('app:health-check is process-only and succeeds regardless of the ruleset', function () {
    // Liveness must not consult the database or the clinical ruleset: an empty
    // ruleset is a readiness concern, never a reason to restart a healthy pod.
    $this->artisan('app:health-check')
        ->assertSuccessful()
        ->expectsOutputToContain('ok');

    expect(DB::table('allergens')->count())->toBe(0)
        ->and(DB::table('dosage_rules')->count())->toBe(0)
        ->and(DB::table('drug_interaction_rules')->count())->toBe(0);
});

test('app:readiness-check succeeds when the database is reachable', function () {
    $this->artisan('app:readiness-check')
        ->assertSuccessful()
        ->expectsOutputToContain('ok');
});
