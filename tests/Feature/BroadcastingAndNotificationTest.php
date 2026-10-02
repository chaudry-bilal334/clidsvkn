<?php

use App\Events\AppointmentStatusChanged;
use App\Events\LowStockAlertTriggered;
use App\Events\SecurityEventLogged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

pest()->use(RefreshDatabase::class);

test('appointment status changes dispatch a broadcast event', function () {
    Event::fake([
        AppointmentStatusChanged::class,
    ]);

    AppointmentStatusChanged::dispatch(
        ['id' => 1, 'time' => '10:00'],
        'booked',
        'Jane Doe',
        'Dr. Smith',
    );

    Event::assertDispatched(AppointmentStatusChanged::class, static function (AppointmentStatusChanged $event): bool {
        return $event->status === 'booked'
            && $event->broadcastAs() === 'AppointmentStatusChanged'
            && collect($event->broadcastOn())
                ->map(fn ($channel) => $channel->name)
                ->all() === ['private-clinic.roles.doctor', 'private-clinic.roles.receptionist'];
    });
});

test('low stock alerts dispatch a broadcast event', function () {
    Event::fake([
        LowStockAlertTriggered::class,
    ]);

    LowStockAlertTriggered::dispatch(
        ['id' => 2, 'name' => 'Amoxicillin 500mg'],
        5,
        20,
    );

    Event::assertDispatched(LowStockAlertTriggered::class, static function (LowStockAlertTriggered $event): bool {
        return $event->currentStock === 5
            && $event->broadcastAs() === 'LowStockAlertTriggered'
            && collect($event->broadcastOn())
                ->map(fn ($channel) => $channel->name)
                ->all() === ['private-clinic.roles.pharmacist', 'private-clinic.roles.admin'];
    });
});

test('security events dispatch a broadcast event', function () {
    Event::fake([
        SecurityEventLogged::class,
    ]);

    SecurityEventLogged::dispatch(
        ['id' => 90210, 'ip_address' => '203.0.113.7'],
        'failed_login',
        null,
        '203.0.113.7',
    );

    Event::assertDispatched(SecurityEventLogged::class, static function (SecurityEventLogged $event): bool {
        return $event->type === 'failed_login'
            && $event->broadcastAs() === 'SecurityEventLogged'
            && collect($event->broadcastOn())
                ->map(fn ($channel) => $channel->name)
                ->all() === ['private-clinic.roles.admin'];
    });
});

test('broadcast events are not dispatched when not faked', function () {
    Event::fake([
        AppointmentStatusChanged::class,
    ]);

    Event::assertNotDispatched(LowStockAlertTriggered::class);
});

test('a doctor can authorize their appointment channel by default', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);

    $this->actingAs($doctor)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-clinic.roles.doctor',
            'socket_id' => '112233.445566',
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

test('opting out of appointment alerts revokes appointment channel access', function () {
    $doctor = User::factory()->create([
        'role' => 'Doctor',
        'notification_preferences' => ['appointments' => false],
    ]);

    $this->actingAs($doctor)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-clinic.roles.doctor',
            'socket_id' => '112233.445566',
        ])
        ->assertForbidden();
});

test('opting out of one category does not block other consented channels', function () {
    $doctor = User::factory()->create([
        'role' => 'Doctor',
        'notification_preferences' => ['security' => false],
    ]);

    $this->actingAs($doctor)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-clinic.roles.doctor',
            'socket_id' => '112233.445566',
        ])
        ->assertOk();
});

test('an admin must consent to both low stock and security alerts', function () {
    $optedOut = User::factory()->create([
        'role' => 'Admin',
        'notification_preferences' => ['security' => false],
    ]);

    $this->actingAs($optedOut)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-clinic.roles.admin',
            'socket_id' => '112233.445566',
        ])
        ->assertForbidden();

    $admin = User::factory()->create(['role' => 'Admin']);

    $this->actingAs($admin)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-clinic.roles.admin',
            'socket_id' => '112233.445566',
        ])
        ->assertOk();
});

test('a receptionist cannot authorize the pharmacy channel', function () {
    $receptionist = User::factory()->create(['role' => 'Receptionist']);

    $this->actingAs($receptionist)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-clinic.roles.pharmacist',
            'socket_id' => '112233.445566',
        ])
        ->assertForbidden();
});

test('a user can only authorize their own personal channel', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-clinic.users.{$user->id}",
            'socket_id' => '112233.445566',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-clinic.users.{$other->id}",
            'socket_id' => '112233.445566',
        ])
        ->assertForbidden();
});
