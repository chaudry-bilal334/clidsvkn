<?php

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\Vital;
use App\Services\DoctorAvailabilityService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function ifc_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function ifc_receptionist(?User $doctor = null): User
{
    $doctor ??= ifc_doctor();

    return User::factory()->create(['role' => 'Receptionist', 'branch_id' => $doctor->branch_id]);
}

function ifc_patient(?User $doctor = null): Patient
{
    $doctor ??= ifc_doctor();

    return Patient::factory()->create(['branch_id' => $doctor->branch_id]);
}

/**
 * Open a bookable window around the given slot.
 */
function ifc_open_window(User $doctor, CarbonInterface $slot): void
{
    app(DoctorAvailabilityService::class)->createSchedule([
        'doctor_id' => $doctor->id,
        'day_of_week' => $slot->dayOfWeek,
        'start_time' => $slot->format('H:i'),
        'end_time' => $slot->copy()->addHours(3)->format('H:i'),
        'is_active' => true,
    ]);
}

test('a booked appointment can be checked in, seen by the doctor and finalized as an encounter', function () {
    $doctor = ifc_doctor();
    $receptionist = ifc_receptionist($doctor);
    $patient = ifc_patient($doctor);

    // 1. Book the appointment.
    $slot = now()->addDays(1)->startOfHour();

    ifc_open_window($doctor, $slot);

    $this->actingAs($receptionist)
        ->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $slot->format('Y-m-d H:i:s'),
            'reason' => 'Follow-up',
        ])
        ->assertCreated();

    $appointment = Appointment::firstOrFail();
    expect($appointment->status)->toBe(Appointment::STATUS_BOOKED);

    // 2. Reception checks the patient in.
    $this->actingAs($receptionist)
        ->postJson('/api/v1/queue', ['appointment_id' => $appointment->id])
        ->assertCreated()
        ->assertJsonPath('data.status', QueueEntry::STATUS_ARRIVED)
        ->assertJsonPath('data.patient_id', $patient->id);

    $entry = QueueEntry::firstOrFail();

    // 3. The queue advances arrived -> waiting -> in_consultation.
    $this->actingAs($receptionist)
        ->putJson("/api/v1/queue/{$entry->id}", ['status' => QueueEntry::STATUS_WAITING])
        ->assertOk()
        ->assertJsonPath('data.status', QueueEntry::STATUS_WAITING);

    $this->actingAs($receptionist)
        ->putJson("/api/v1/queue/{$entry->id}", ['status' => QueueEntry::STATUS_IN_CONSULTATION])
        ->assertOk()
        ->assertJsonPath('data.status', QueueEntry::STATUS_IN_CONSULTATION);

    // 4. The doctor opens an encounter, records vitals, then finalizes it.
    $this->actingAs($doctor)
        ->postJson('/api/v1/encounters', [
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
        ])
        ->assertCreated();

    $encounter = Encounter::firstOrFail();
    expect($encounter->status)->toBe(Encounter::STATUS_DRAFT)
        ->and($encounter->appointment_id)->toBe($appointment->id);

    $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$encounter->id}/vitals", [
            'blood_pressure' => '120/80',
            'heart_rate' => 72,
        ])
        ->assertCreated();

    $this->actingAs($doctor)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/encounters/{$encounter->id}/finalize", [
            'chief_complaint' => 'Routine follow-up',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', Encounter::STATUS_FINALIZED);

    $encounter->refresh();
    expect($encounter->status)->toBe(Encounter::STATUS_FINALIZED)
        ->and($encounter->finalized_at)->not->toBeNull()
        ->and($encounter->finalized_by)->toBe($doctor->id);

    // 5. Consultation is closed out on the queue.
    $this->actingAs($receptionist)
        ->putJson("/api/v1/queue/{$entry->id}", ['status' => QueueEntry::STATUS_COMPLETED])
        ->assertOk()
        ->assertJsonPath('data.status', QueueEntry::STATUS_COMPLETED);

    expect(Vital::where('encounter_id', $encounter->id)->count())->toBe(1);
});

test('the clinical record of a completed visit stays consistent across encounter, queue and appointment', function () {
    $doctor = ifc_doctor();
    $receptionist = ifc_receptionist($doctor);
    $patient = ifc_patient($doctor);
    $slot = now()->addDays(1)->startOfHour();

    ifc_open_window($doctor, $slot);

    $this->actingAs($receptionist)
        ->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $slot->format('Y-m-d H:i:s'),
        ])
        ->assertCreated();

    $appointment = Appointment::firstOrFail();

    $this->actingAs($receptionist)
        ->postJson('/api/v1/queue', ['appointment_id' => $appointment->id])
        ->assertCreated();

    $entry = QueueEntry::firstOrFail();

    $this->actingAs($receptionist)
        ->putJson("/api/v1/queue/{$entry->id}", ['status' => QueueEntry::STATUS_WAITING])
        ->assertOk();

    $this->actingAs($receptionist)
        ->putJson("/api/v1/queue/{$entry->id}", ['status' => QueueEntry::STATUS_IN_CONSULTATION])
        ->assertOk();

    $this->actingAs($doctor)
        ->postJson('/api/v1/encounters', [
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
        ])
        ->assertCreated();

    $encounter = Encounter::firstOrFail();

    $this->actingAs($doctor)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/encounters/{$encounter->id}/finalize")
        ->assertOk();

    $this->actingAs($receptionist)
        ->putJson("/api/v1/queue/{$entry->id}", ['status' => QueueEntry::STATUS_COMPLETED])
        ->assertOk();

    // Every stage agrees on the same patient and appointment.
    expect($entry->fresh()->patient_id)->toBe($patient->id)
        ->and($entry->fresh()->appointment_id)->toBe($appointment->id)
        ->and($encounter->fresh()->patient_id)->toBe($patient->id)
        ->and($encounter->fresh()->appointment_id)->toBe($appointment->id);

    // The whole visit is auditable.
    $actions = AuditLog::query()
        ->whereIn('action', [
            'encounter.created',
            'encounter.finalized',
            'queue.checked_in',
        ])
        ->pluck('action')
        ->all();

    expect($actions)->toContain('encounter.created', 'encounter.finalized', 'queue.checked_in');
});

test('an encounter cannot be finalized twice', function () {
    $doctor = ifc_doctor();
    $patient = ifc_patient($doctor);

    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => Encounter::STATUS_DRAFT,
    ]);

    $this->actingAs($doctor)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/encounters/{$encounter->id}/finalize")
        ->assertOk();

    $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$encounter->id}/finalize")
        ->assertStatus(422);

    expect($encounter->fresh()->status)->toBe(Encounter::STATUS_FINALIZED);
});
