<?php

use App\Events\ChatMessageSent;
use App\Events\TelehealthSessionUpdated;
use App\Models\Patient;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

pest()->use(RefreshDatabase::class);

test('starting a telehealth session persists it and broadcasts a session update', function () {
    Event::fake([TelehealthSessionUpdated::class]);

    $patient = Patient::factory()->create();
    $doctor = User::factory()->create(['role' => 'Doctor']);

    $this->actingAs($doctor)
        ->postJson('/api/v1/telehealth/sessions', ['patient_id' => $patient->id])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.patient_name', $patient->name)
        ->assertJsonPath('data.doctor_name', $doctor->name);

    $session = TelehealthSession::query()->first();
    expect($session)->not->toBeNull()
        ->and($session->patient_id)->toBe($patient->id)
        ->and($session->doctor_id)->toBe((int) $doctor->id);

    Event::assertDispatched(TelehealthSessionUpdated::class, static function (TelehealthSessionUpdated $event) use ($session): bool {
        return $event->session->is($session)
            && $event->status === 'active'
            && $event->broadcastAs() === 'TelehealthSessionUpdated'
            && collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all()
                === ["private-clinic.telehealth.{$session->id}"];
    });
});

test('sending a chat message persists it and broadcasts ChatMessageSent', function () {
    Event::fake([ChatMessageSent::class]);

    $doctor = User::factory()->create(['role' => 'Doctor']);
    $session = TelehealthSession::factory()->create(['doctor_id' => $doctor->id]);

    $this->actingAs($doctor)
        ->postJson("/api/v1/telehealth/sessions/{$session->id}/messages", ['message' => 'Your results look great.'])
        ->assertCreated()
        ->assertJsonPath('data.sender_name', $doctor->name)
        ->assertJsonPath('data.message', 'Your results look great.');

    expect($session->messages()->count())->toBe(1);

    Event::assertDispatched(ChatMessageSent::class, static function (ChatMessageSent $event) use ($session, $doctor): bool {
        return $event->sessionId === (int) $session->id
            && $event->senderName === $doctor->name
            && $event->message === 'Your results look great.'
            && $event->broadcastAs() === 'ChatMessageSent'
            && collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all()
                === ["private-clinic.telehealth.{$session->id}"];
    });
});

test('a message is required to send chat', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $session = TelehealthSession::factory()->create(['doctor_id' => $doctor->id]);

    $this->actingAs($doctor)
        ->postJson("/api/v1/telehealth/sessions/{$session->id}/messages", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);

    expect($session->messages()->count())->toBe(0);
});

test('ending a session broadcasts a session update with ended status', function () {
    Event::fake([TelehealthSessionUpdated::class]);

    $doctor = User::factory()->create(['role' => 'Doctor']);
    $session = TelehealthSession::factory()->create(['doctor_id' => $doctor->id]);

    $this->actingAs($doctor)
        ->patchJson("/api/v1/telehealth/sessions/{$session->id}/end")
        ->assertOk()
        ->assertJsonPath('data.status', 'ended');

    expect($session->fresh()->status)->toBe('ended');

    Event::assertDispatched(TelehealthSessionUpdated::class, static function (TelehealthSessionUpdated $event) use ($session): bool {
        return $event->session->is($session)
            && $event->status === 'ended'
            && $event->broadcastAs() === 'TelehealthSessionUpdated'
            && collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all()
                === ["private-clinic.telehealth.{$session->id}"];
    });
});

test('a doctor can only list their own telehealth sessions', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $other = User::factory()->create(['role' => 'Doctor']);

    TelehealthSession::factory()->create(['doctor_id' => $doctor->id]);
    $foreign = TelehealthSession::factory()->create(['doctor_id' => $other->id]);

    $this->actingAs($doctor)
        ->getJson('/api/v1/telehealth/sessions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['id' => $foreign->id]);
});

test('the session doctor can authorize their telehealth channel', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $session = TelehealthSession::factory()->create(['doctor_id' => $doctor->id]);

    $this->actingAs($doctor)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-clinic.telehealth.{$session->id}",
            'socket_id' => '112233.445566',
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

test('an admin can authorize any telehealth channel', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $session = TelehealthSession::factory()->create();

    $this->actingAs($admin)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-clinic.telehealth.{$session->id}",
            'socket_id' => '112233.445566',
        ])
        ->assertOk();
});

test('a patient can authorize their own telehealth channel', function () {
    $patient = Patient::factory()->create();
    $user = User::factory()->create(['role' => 'Patient', 'patient_id' => $patient->id]);
    $session = TelehealthSession::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-clinic.telehealth.{$session->id}",
            'socket_id' => '112233.445566',
        ])
        ->assertOk();
});

test('staff and unrelated patients cannot authorize a telehealth channel', function () {
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();

    $otherPatient = User::factory()->create(['role' => 'Patient', 'patient_id' => $patientB->id]);
    $receptionist = User::factory()->create(['role' => 'Receptionist']);
    $session = TelehealthSession::factory()->create(['patient_id' => $patientA->id]);

    $this->actingAs($otherPatient)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-clinic.telehealth.{$session->id}",
            'socket_id' => '112233.445566',
        ])
        ->assertForbidden();

    $this->actingAs($receptionist)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-clinic.telehealth.{$session->id}",
            'socket_id' => '112233.445566',
        ])
        ->assertForbidden();
});

test('an unknown session id cannot be authorized', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);

    $this->actingAs($doctor)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-clinic.telehealth.999999',
            'socket_id' => '112233.445566',
        ])
        ->assertForbidden();
});
