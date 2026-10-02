<?php

use App\Events\AppointmentStatusChanged;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

pest()->use(RefreshDatabase::class);

function doctorAccount(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function bookingUser(): User
{
    return User::factory()->create(['role' => 'Receptionist']);
}

function scheduleFor(User $doctor, Carbon $date, string $start = '09:00', string $end = '12:00'): DoctorSchedule
{
    return DoctorSchedule::factory()
        ->on((string) $date->dayOfWeek, $start, $end)
        ->create(['doctor_id' => $doctor->id]);
}

function futureDate(): Carbon
{
    return now()->addDays(8)->startOfDay();
}

function bookAppointment(User $doctor, Patient $patient, string $scheduledAt): Appointment
{
    return Appointment::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'scheduled_at' => $scheduledAt,
        'status' => 'booked',
    ]);
}

test('the doctors endpoint lists only doctor accounts', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $doctor = doctorAccount();
    $doctor->name = 'Dr. Sarah Chen';
    $doctor->save();

    $this->actingAs(bookingUser())
        ->getJson('/api/v1/doctors')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Dr. Sarah Chen');

    $this->actingAs(
        User::factory()->create(['role' => 'Pharmacist']),
    )->getJson('/api/v1/doctors')
        ->assertForbidden();

    $this->actingAs($admin)->getJson('/api/v1/doctors')->assertOk();
});

test('availability slots strictly follow the active weekly schedule', function () {
    $doctor = doctorAccount();
    $date = futureDate();
    scheduleFor($doctor, $date);

    $this->actingAs(bookingUser())
        ->getJson('/api/v1/doctors/'.$doctor->id.'/availability?date='.$date->format('Y-m-d'))
        ->assertOk()
        ->assertJsonPath('data.slot_minutes', 30)
        ->assertJsonPath('data.is_on_time_off', false)
        ->assertJsonCount(6, 'data.slots')
        ->assertJsonPath('data.slots.0.start', '09:00')
        ->assertJsonPath('data.slots.5.start', '11:30')
        ->assertJsonPath('data.slots.5.end', '12:00');
});

test('inactive schedules and unscheduled days produce no slots', function () {
    $doctor = doctorAccount();
    $date = futureDate();

    scheduleFor($doctor, $date)->update(['is_active' => false]);

    $this->actingAs(bookingUser())
        ->getJson('/api/v1/doctors/'.$doctor->id.'/availability?date='.$date->format('Y-m-d'))
        ->assertOk()
        ->assertJsonCount(0, 'data.slots');

    $otherDay = $date->copy()->addDays(6);
    scheduleFor($doctor, $otherDay);

    $this->actingAs(bookingUser())
        ->getJson('/api/v1/doctors/'.$doctor->id.'/availability?date='.$otherDay->format('Y-m-d'))
        ->assertOk()
        ->assertJsonCount(6, 'data.slots');
});

test('booked appointments mark their slot unavailable', function () {
    $doctor = doctorAccount();
    $patient = Patient::factory()->create();
    $date = futureDate();
    $dateStr = $date->format('Y-m-d');
    scheduleFor($doctor, $date);

    bookAppointment($doctor, $patient, $dateStr.' 09:30:00');

    $response = $this->actingAs(bookingUser())
        ->getJson('/api/v1/doctors/'.$doctor->id.'/availability?date='.$dateStr)
        ->assertOk()
        ->assertJsonPath('data.slots.0.is_available', true)
        ->assertJsonPath('data.slots.1.is_available', false);

    collect($response->json('data.slots'))->each(function (array $slot, int $index): void {
        if ($index === 1) {
            expect($slot['is_available'])->toBeFalse();
        } else {
            expect($slot['is_available'])->toBeTrue();
        }
    });
});

test('approved time off blocks the entire working day', function () {
    $doctor = doctorAccount();
    $patient = Patient::factory()->create();
    $date = futureDate();
    $dateStr = $date->format('Y-m-d');
    scheduleFor($doctor, $date);

    DoctorTimeOff::factory()->covering($dateStr)->create(['doctor_id' => $doctor->id]);

    $response = $this->actingAs(bookingUser())
        ->getJson('/api/v1/doctors/'.$doctor->id.'/availability?date='.$dateStr)
        ->assertOk()
        ->assertJsonPath('data.is_on_time_off', true);

    collect($response->json('data.slots'))->each(
        fn (array $slot) => expect($slot['is_available'])->toBeFalse(),
    );

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $dateStr.' 09:30:00',
            'reason' => 'Follow-up',
        ])
        ->assertUnprocessable();
});

test('appointments can only be created inside an available slot', function () {
    Event::fake([AppointmentStatusChanged::class]);

    $doctor = doctorAccount();
    $patient = Patient::factory()->create();
    $date = futureDate();
    $dateStr = $date->format('Y-m-d');
    scheduleFor($doctor, $date);

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $dateStr.' 09:30:00',
            'reason' => 'Routine check',
        ])
        ->assertCreated()
        ->assertJsonPath('data.time', '09:30')
        ->assertJsonPath('data.status', 'booked');

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $dateStr.' 08:30:00',
        ])
        ->assertUnprocessable();

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $dateStr.' 12:30:00',
        ])
        ->assertUnprocessable();

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $dateStr.' 09:15:00',
        ])
        ->assertUnprocessable();
});

test('double booking is rejected but adjacent slots remain bookable', function () {
    Event::fake([AppointmentStatusChanged::class]);

    $doctor = doctorAccount();
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();
    $date = futureDate();
    $dateStr = $date->format('Y-m-d');
    scheduleFor($doctor, $date);

    bookAppointment($doctor, $patientA, $dateStr.' 09:30:00');

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/appointments', [
            'patient_id' => $patientB->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $dateStr.' 09:30:00',
        ])
        ->assertUnprocessable();

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/appointments', [
            'patient_id' => $patientB->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $dateStr.' 09:00:00',
        ])
        ->assertCreated();

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/appointments', [
            'patient_id' => $patientB->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $dateStr.' 10:00:00',
        ])
        ->assertCreated();
});

test('appointments cannot be scheduled in the past', function () {
    $doctor = doctorAccount();
    $patient = Patient::factory()->create();
    $yesterday = now()->subDay()->format('Y-m-d');

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $yesterday.' 09:30:00',
        ])
        ->assertUnprocessable();
});

test('appointments can be rescheduled to a free slot but not a busy one', function () {
    $doctor = doctorAccount();
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();
    $date = futureDate();
    $dateStr = $date->format('Y-m-d');
    scheduleFor($doctor, $date);

    $appointment = bookAppointment($doctor, $patientA, $dateStr.' 09:00:00');
    bookAppointment($doctor, $patientB, $dateStr.' 09:30:00');

    $this->actingAs(bookingUser())
        ->putJson('/api/v1/appointments/'.$appointment->id, ['scheduled_at' => $dateStr.' 09:30:00'])
        ->assertUnprocessable();

    $this->actingAs(bookingUser())
        ->putJson('/api/v1/appointments/'.$appointment->id, ['scheduled_at' => $dateStr.' 10:00:00'])
        ->assertOk()
        ->assertJsonPath('data.time', '10:00');

    $this->actingAs(bookingUser())
        ->putJson('/api/v1/appointments/'.$appointment->id, ['scheduled_at' => $dateStr.' 08:00:00'])
        ->assertUnprocessable();
});

test('weekly schedules can be managed via the CRUD endpoints', function () {
    $doctor = doctorAccount();
    $dow = futureDate()->dayOfWeek;

    $this->actingAs(bookingUser())
        ->getJson('/api/v1/doctors/'.$doctor->id.'/schedules')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/doctors/'.$doctor->id.'/schedules', [
            'day_of_week' => $dow,
            'start_time' => '10:00',
            'end_time' => '13:00',
            'is_active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.start_time', '10:00');

    $schedule = DoctorSchedule::firstOrFail();

    $this->actingAs(bookingUser())
        ->putJson('/api/v1/schedules/'.$schedule->id, [
            'day_of_week' => $dow,
            'start_time' => '11:00',
            'end_time' => '14:00',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.start_time', '11:00')
        ->assertJsonPath('data.is_active', false);

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/doctors/'.$doctor->id.'/schedules', [
            'day_of_week' => $dow,
            'start_time' => '14:00',
            'end_time' => '12:00',
        ])
        ->assertUnprocessable();

    $this->actingAs(bookingUser())
        ->deleteJson('/api/v1/schedules/'.$schedule->id)
        ->assertOk();

    $this->actingAs(bookingUser())
        ->getJson('/api/v1/doctors/'.$doctor->id.'/schedules')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('time-off requests can be managed and lift when deleted', function () {
    $doctor = doctorAccount();
    $date = futureDate();
    $dateStr = $date->format('Y-m-d');
    scheduleFor($doctor, $date);

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/doctors/'.$doctor->id.'/time-offs', [
            'start_date' => $dateStr,
            'end_date' => $dateStr,
            'reason' => 'Conference',
        ])
        ->assertCreated()
        ->assertJsonPath('data.reason', 'Conference');

    $timeOff = DoctorTimeOff::firstOrFail();

    $this->actingAs(bookingUser())
        ->getJson('/api/v1/doctors/'.$doctor->id.'/availability?date='.$dateStr)
        ->assertJsonPath('data.is_on_time_off', true);

    $this->actingAs(bookingUser())
        ->postJson('/api/v1/doctors/'.$doctor->id.'/time-offs', [
            'start_date' => $dateStr,
            'end_date' => $date->copy()->subDay()->format('Y-m-d'),
        ])
        ->assertUnprocessable();

    $this->actingAs(bookingUser())
        ->putJson('/api/v1/time-offs/'.$timeOff->id, [
            'start_date' => $dateStr,
            'end_date' => $date->copy()->addDay()->format('Y-m-d'),
            'reason' => 'Annual leave',
        ])
        ->assertOk();

    $this->actingAs(bookingUser())
        ->deleteJson('/api/v1/time-offs/'.$timeOff->id)
        ->assertOk();

    $this->actingAs(bookingUser())
        ->getJson('/api/v1/doctors/'.$doctor->id.'/availability?date='.$dateStr)
        ->assertOk()
        ->assertJsonPath('data.is_on_time_off', false)
        ->assertJsonCount(6, 'data.slots');

    expect(DoctorTimeOff::withTrashed()->find($timeOff->id))->not->toBeNull();
});

test('pharmacy staff cannot reach scheduling endpoints', function () {
    $doctor = doctorAccount();
    $pharmacist = User::factory()->create(['role' => 'Pharmacist']);

    $this->actingAs($pharmacist)
        ->getJson('/api/v1/doctors/'.$doctor->id.'/availability?date='.futureDate()->format('Y-m-d'))
        ->assertForbidden();

    $this->actingAs($pharmacist)
        ->postJson('/api/v1/doctors/'.$doctor->id.'/schedules', [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ])
        ->assertForbidden();
});
