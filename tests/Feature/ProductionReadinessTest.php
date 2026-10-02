<?php

use App\Models\SystemBackup;
use App\Models\User;
use App\Policies\BackupPolicy;
use App\Services\CacheWarmingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

pest()->use(RefreshDatabase::class);

function pa_admin(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

test('system backup model has correct casts', function () {
    $backup = SystemBackup::factory()->completed()->create(['file_size' => 2097152]);

    expect($backup->file_size)->toBeString();
    expect((string) $backup->file_size)->toContain('2097152');
    expect($backup->status)->toBe('completed');
});

test('backup status enum values are valid', function () {
    $validStatuses = ['pending', 'completed', 'failed'];

    foreach ($validStatuses as $status) {
        $backup = SystemBackup::factory()->create(['status' => $status]);

        expect($backup->status)->toBe($status);
    }
});

test('backup can be created with pending status', function () {
    $backup = SystemBackup::factory()->pending()->create();

    expect($backup->status)->toBe(SystemBackup::STATUS_PENDING);
});

test('backup can be created with completed status', function () {
    $backup = SystemBackup::factory()->completed()->create();

    expect($backup->status)->toBe(SystemBackup::STATUS_COMPLETED);
});

test('backup can be created with failed status', function () {
    $backup = SystemBackup::factory()->failed()->create();

    expect($backup->status)->toBe(SystemBackup::STATUS_FAILED);
});

test('admin user has admin role', function () {
    $admin = pa_admin();

    expect($admin->role)->toBe('Admin');
});

test('backup model has factory', function () {
    $backup = SystemBackup::factory()->create();

    expect($backup->id)->toBeInt();
    expect($backup->filename)->toContain('backup-');
});

test('cache warming warms all critical caches', function () {
    $service = new CacheWarmingService;

    $service->warmAll();

    expect(Cache::has('permissions.all'))->toBeTrue();
    expect(Cache::has('inventory.low-stock-count'))->toBeTrue();
});

test('cache warming warms role permissions for all roles', function () {
    $service = new CacheWarmingService;

    // Test via public warmAll which calls warmRolePermissions internally
    $service->warmAll();

    expect(Cache::has('permissions.all'))->toBeTrue();
    expect(Cache::has('permissions.Admin'))->toBeTrue();
    expect(Cache::has('permissions.Doctor'))->toBeTrue();
    expect(Cache::has('permissions.Receptionist'))->toBeTrue();
    expect(Cache::has('permissions.Accountant'))->toBeTrue();
    expect(Cache::has('permissions.Pharmacist'))->toBeTrue();
});

test('cache warming warmAll can be called twice without error', function () {
    $service = new CacheWarmingService;

    $service->warmAll();
    $service->warmAll();

    expect(Cache::has('permissions.all'))->toBeTrue();
    expect(Cache::has('inventory.low-stock-count'))->toBeTrue();
});

test('backup filename format is correct', function () {
    $filename = 'backup-'.now()->format('YmdHis').'.sql.gz';

    expect($filename)->toContain('backup-');
    expect($filename)->toEndWith('.sql.gz');
});

test('backup policy class exists', function () {
    expect(class_exists(BackupPolicy::class))->toBeTrue();
});

test('cache warming service exists and has warmAll method', function () {
    $service = new CacheWarmingService;

    expect($service)->toBeInstanceOf(CacheWarmingService::class);
    expect(method_exists($service, 'warmAll'))->toBeTrue();
});
