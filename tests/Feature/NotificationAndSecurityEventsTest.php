<?php

use App\Models\NotificationLog;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

test('admin can list security events with filters', function () {
    SecurityEvent::factory()->create(['severity' => 'critical', 'type' => 'login_failed']);
    SecurityEvent::factory()->create(['severity' => 'info', 'type' => 'session_started']);

    $admin = User::factory()->create(['role' => 'Admin']);

    $index = $this->actingAs($admin)->getJson('/api/v1/security-events')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($index->json('data.0.action'))->not->toBeNull()
        ->and($index->json('meta.total'))->toBe(2);

    $this->actingAs($admin)->getJson('/api/v1/security-events?severity=critical')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.severity', 'critical');
});

test('security events are forbidden below admin', function () {
    SecurityEvent::factory()->create();

    $receptionist = User::factory()->create(['role' => 'Receptionist']);

    $this->actingAs($receptionist)->getJson('/api/v1/security-events')->assertForbidden();
    $this->actingAs($receptionist)->getJson('/api/v1/security-events')->assertForbidden();
});

test('a user only ever sees their own notification log', function () {
    $user = User::factory()->create(['role' => 'Receptionist']);
    $other = User::factory()->create(['role' => 'Doctor']);

    NotificationLog::create(['user_id' => $user->id, 'channel' => 'email', 'title' => 'Appointment reminder', 'status' => 'sent']);
    NotificationLog::create(['user_id' => $other->id, 'channel' => 'whatsapp', 'title' => 'Other user note', 'status' => 'sent']);

    $this->actingAs($user)->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.message', 'Appointment reminder');
});

test('a user can mark only their own notification as read', function () {
    $user = User::factory()->create(['role' => 'Receptionist']);
    $other = User::factory()->create(['role' => 'Doctor']);

    $own = NotificationLog::create(['user_id' => $user->id, 'channel' => 'sms', 'title' => 'Own', 'status' => 'sent']);
    $theirs = NotificationLog::create(['user_id' => $other->id, 'channel' => 'sms', 'title' => 'Theirs', 'status' => 'sent']);

    $this->actingAs($user)->putJson("/api/v1/notifications/{$own->id}/read")->assertOk();
    expect($own->fresh()->read_at)->not->toBeNull();

    $this->actingAs($user)->putJson("/api/v1/notifications/{$theirs->id}/read")->assertForbidden();
    expect($theirs->fresh()->read_at)->toBeNull();
});

test('channel preferences are toggleable and validated', function () {
    $user = User::factory()->create(['role' => 'Receptionist', 'notification_preferences' => ['email' => true, 'sms' => true, 'whatsapp' => true]]);

    $this->actingAs($user)->getJson('/api/v1/notifications/channels')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $this->actingAs($user)->putJson('/api/v1/notifications/channels/whatsapp', ['enabled' => false])->assertOk();
    expect($user->fresh()->notification_preferences['whatsapp'])->toBeFalse();

    $this->actingAs($user)->putJson('/api/v1/notifications/channels/telegram', ['enabled' => true])->assertStatus(422);
});
