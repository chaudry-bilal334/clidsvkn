<?php

use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function dsc_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

/**
 * @return array{0: User, 1: string}
 */
function dsc_window(): array
{
    $doctor = dsc_doctor();
    $date = now()->addDays(1)->startOfHour();

    return [$doctor, $date->format('H:i')];
}

test('a weekly schedule can be created through the API', function () {
    [$doctor, $start] = dsc_window();

    $this->actingAs($doctor)
        ->postJson("/api/v1/doctors/{$doctor->id}/schedules", [
            'day_of_week' => 1,
            'start_time' => $start,
            'end_time' => '17:00',
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.start_time', $start);

    expect(DoctorSchedule::where('doctor_id', $doctor->id)->count())->toBe(1);
});

test('an overlapping schedule is rejected with 422', function () {
    [$doctor, $start] = dsc_window();

    $this->actingAs($doctor)
        ->postJson("/api/v1/doctors/{$doctor->id}/schedules", [
            'day_of_week' => 1,
            'start_time' => $start,
            'end_time' => '17:00',
        ])
        ->assertCreated();

    $this->actingAs($doctor)
        ->postJson("/api/v1/doctors/{$doctor->id}/schedules", [
            'day_of_week' => 1,
            'start_time' => '12:00',
            'end_time' => '18:00',
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(DoctorSchedule::where('doctor_id', $doctor->id)->count())->toBe(1);
});

test('a schedule window that ends before it starts is rejected with 422', function () {
    [$doctor] = dsc_window();

    $this->actingAs($doctor)
        ->postJson("/api/v1/doctors/{$doctor->id}/schedules", [
            'day_of_week' => 1,
            'start_time' => '14:00',
            'end_time' => '12:00',
        ])
        ->assertStatus(422);

    expect(DoctorSchedule::where('doctor_id', $doctor->id)->count())->toBe(0);
});

test('a schedule can be rescheduled to a free window', function () {
    [$doctor, $start] = dsc_window();

    $this->actingAs($doctor)
        ->postJson("/api/v1/doctors/{$doctor->id}/schedules", [
            'day_of_week' => 1,
            'start_time' => $start,
            'end_time' => '17:00',
        ])
        ->assertCreated();

    $schedule = DoctorSchedule::firstOrFail();

    $this->actingAs($doctor)
        ->putJson("/api/v1/schedules/{$schedule->id}", [
            'day_of_week' => 1,
            'start_time' => '18:00',
            'end_time' => '20:00',
        ])
        ->assertOk()
        ->assertJsonPath('data.start_time', '18:00')
        ->assertJsonPath('data.end_time', '20:00');
});

test('availability omits slots that an active appointment already occupies', function () {
    $doctor = dsc_doctor();
    $date = now()->addDays(1)->startOfHour();
    $start = $date->format('H:i');
    $end = $date->copy()->addHours(3)->format('H:i');

    $this->actingAs($doctor)
        ->postJson("/api/v1/doctors/{$doctor->id}/schedules", [
            'day_of_week' => $date->dayOfWeek,
            'start_time' => $start,
            'end_time' => $end,
        ])
        ->assertCreated();

    Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => Patient::factory()->create(['branch_id' => $doctor->branch_id])->id,
        'scheduled_at' => $date->format('Y-m-d H:i:s'),
        'status' => Appointment::STATUS_BOOKED,
    ]);

    $response = $this->actingAs($doctor)
        ->getJson("/api/v1/doctors/{$doctor->id}/availability?date={$date->toDateString()}")
        ->assertOk()
        ->assertJsonPath('success', true);

    $slots = collect($response->json('data.slots'));
    $booked = $slots->firstWhere('start', $start);

    expect($booked)->not->toBeNull()
        ->and($booked['is_available'])->toBeFalse()
        ->and($slots->where('start', $date->copy()->addMinutes(30)->format('H:i'))->first()['is_available'])->toBeTrue();
});
