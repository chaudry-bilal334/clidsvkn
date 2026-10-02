<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

pest()->use(RefreshDatabase::class);

/**
 * Execute the migration class directly against the current connection,
 * bypassing the Artisan batch tracker so each migration is tested in
 * isolation.
 */
function runMigrationUp(string $migrationPath): void
{
    $migration = require database_path($migrationPath);
    $migration->up();
}

function runMigrationDown(string $migrationPath): void
{
    $migration = require database_path($migrationPath);
    $migration->down();
}

test('roles and permissions tables migration is reversible', function () {
    $tables = ['roles', 'permissions', 'permission_role', 'permission_user', 'role_user'];
    $columns = [
        'roles' => ['id', 'name', 'guard_name', 'created_at', 'updated_at'],
        'permissions' => ['id', 'name', 'guard_name', 'created_at', 'updated_at'],
        'permission_role' => ['id', 'permission_id', 'role_id'],
        'permission_user' => ['id', 'permission_id', 'user_id'],
        'role_user' => ['id', 'role_id', 'user_id'],
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
        foreach ($columns[$table] as $column) {
            expect(Schema::hasColumn($table, $column))->toBeTrue("$table.$column should exist");
        }
    }

    runMigrationDown('migrations/2026_09_14_000004_create_roles_and_permissions_tables.php');

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse("$table should be dropped by down()");
    }

    runMigrationUp('migrations/2026_09_14_000004_create_roles_and_permissions_tables.php');

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("$table should be re-created by up()");
    }
});

test('security_events altered columns are reversible', function () {
    $columns = ['id', 'user_id', 'type', 'severity', 'ip_address', 'user_agent', 'payload', 'created_at', 'updated_at'];

    expect(Schema::hasTable('security_events'))->toBeTrue();
    foreach ($columns as $column) {
        expect(Schema::hasColumn('security_events', $column))->toBeTrue("security_events.$column should exist");
    }
    expect(Schema::hasIndex('security_events', ['type']))->toBeTrue('security_events.type should be indexed');

    runMigrationDown('migrations/2026_09_14_000005_create_security_events_table.php');

    expect(Schema::hasColumn('security_events', 'user_agent'))->toBeFalse('user_agent should be dropped by down()')
        ->and(Schema::hasColumn('security_events', 'payload'))->toBeFalse('payload should be dropped by down()')
        ->and(Schema::hasColumn('security_events', 'details'))->toBeTrue('details should be restored by down()');

    runMigrationUp('migrations/2026_09_14_000005_create_security_events_table.php');

    expect(Schema::hasColumn('security_events', 'user_agent'))->toBeTrue('user_agent should be re-added by up()')
        ->and(Schema::hasColumn('security_events', 'payload'))->toBeTrue('payload should be re-added by up()')
        ->and(Schema::hasColumn('security_events', 'details'))->toBeFalse('details should be removed by up()');
});
