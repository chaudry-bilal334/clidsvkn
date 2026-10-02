<?php

use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use App\Services\DoctorAvailabilityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

pest()->use(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function scheduleWindow(array $overrides = []): array
{
    return array_merge([
        'doctor_id' => null,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '12:00',
        'is_active' => true,
    ], $overrides);
}

test('an overlapping active schedule for the same doctor and weekday is rejected', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $service = app(DoctorAvailabilityService::class);

    $service->createSchedule(scheduleWindow(['doctor_id' => $doctor->id]));

    expect(fn () => $service->createSchedule(scheduleWindow([
        'doctor_id' => $doctor->id,
        'start_time' => '10:00',
        'end_time' => '13:00',
    ])))->toThrow(ValidationException::class, 'This schedule overlaps an existing active schedule');

    expect(DoctorSchedule::where('doctor_id', $doctor->id)->count())->toBe(1);
});

test('non-overlapping schedules on the same weekday are allowed', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $service = app(DoctorAvailabilityService::class);

    $service->createSchedule(scheduleWindow(['doctor_id' => $doctor->id]));

    $second = $service->createSchedule(scheduleWindow([
        'doctor_id' => $doctor->id,
        'start_time' => '14:00',
        'end_time' => '18:00',
    ]));

    expect($second->start_time)->toBe('14:00')
        ->and($second->end_time)->toBe('18:00')
        ->and(DoctorSchedule::where('doctor_id', $doctor->id)->count())->toBe(2);
});

test('a schedule window that ends before it starts is rejected', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);

    expect(fn () => app(DoctorAvailabilityService::class)->createSchedule(scheduleWindow([
        'doctor_id' => $doctor->id,
        'start_time' => '14:00',
        'end_time' => '12:00',
    ])))->toThrow(ValidationException::class, 'The end time must be after the start time');

    expect(DoctorSchedule::where('doctor_id', $doctor->id)->count())->toBe(0);
});

test('deactivating a schedule frees its window for another schedule', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $service = app(DoctorAvailabilityService::class);

    $first = $service->createSchedule(scheduleWindow(['doctor_id' => $doctor->id]));

    $service->updateSchedule($first, ['is_active' => false]);

    $second = $service->createSchedule(scheduleWindow([
        'doctor_id' => $doctor->id,
        'start_time' => '10:00',
        'end_time' => '13:00',
    ]));

    expect($second)->toBeInstanceOf(DoctorSchedule::class);

    expect(fn () => $service->updateSchedule($first->fresh(), ['is_active' => true]))
        ->toThrow(ValidationException::class, 'This schedule overlaps an existing active schedule');
});

test('database unique constraint rejects double booking of a doctor slot', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $slot = now()->addDays(1)->startOfHour()->format('Y-m-d H:i:s');

    Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => Patient::factory()->create()->id,
        'scheduled_at' => $slot,
        'status' => Appointment::STATUS_BOOKED,
    ]);

    expect(fn () => Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => Patient::factory()->create()->id,
        'scheduled_at' => $slot,
        'status' => Appointment::STATUS_BOOKED,
    ]))->toThrow(QueryException::class);

    expect(Appointment::where('doctor_id', $doctor->id)->count())->toBe(1);
});

test('a booked slot is no longer offered by the availability service', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $service = app(DoctorAvailabilityService::class);
    $slot = now()->addDays(1)->startOfHour();

    $service->createSchedule(scheduleWindow([
        'doctor_id' => $doctor->id,
        'day_of_week' => $slot->dayOfWeek,
        'start_time' => $slot->format('H:i'),
        'end_time' => $slot->copy()->addHours(3)->format('H:i'),
    ]));

    expect($service->slotIsAvailable($doctor->id, $slot))->toBeTrue();

    Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => Patient::factory()->create()->id,
        'scheduled_at' => $slot->format('Y-m-d H:i:s'),
        'status' => Appointment::STATUS_BOOKED,
    ]);

    expect($service->slotIsAvailable($doctor->id, $slot))->toBeFalse()
        ->and($service->unavailabilityReason($doctor->id, $slot))->toBe('This time slot has already been booked.');
});
