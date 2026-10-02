<?php

use App\Models\Branch;
use App\Models\Communication;
use App\Models\DoctorSchedule;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function public_branch(): Branch
{
    return Branch::factory()->create(['is_active' => true, 'name' => 'ClinicSuite Lahore']);
}

function public_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

test('public clinic info exposes the active branch identity and services', function () {
    public_branch();

    $this->getJson('/api/v1/public/clinic/info')
        ->assertOk()
        ->assertJsonPath('data.name', 'ClinicSuite Lahore')
        ->assertJsonPath('data.services', config('clinic.services'))
        ->assertJsonPath('success', true);
});

test('clinic info omits the identity when no active branch is configured', function () {
    $this->getJson('/api/v1/public/clinic/info')
        ->assertOk()
        ->assertJsonPath('data.name', null)
        ->assertJsonPath('data.services', config('clinic.services'));
});

test('public services lists the configured marketing services', function () {
    $this->getJson('/api/v1/public/services')
        ->assertOk()
        ->assertJsonPath('data.services', config('clinic.services'));
});

test('the public doctor directory exposes only approved schedule data', function () {
    $doctor = public_doctor();
    DoctorSchedule::factory()->create([
        'doctor_id' => $doctor->id,
        'day_of_week' => 1,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
        'is_active' => true,
    ]);
    DoctorSchedule::factory()->create([
        'doctor_id' => $doctor->id,
        'day_of_week' => 3,
        'start_time' => '17:00:00',
        'end_time' => '21:00:00',
        'is_active' => false,
    ]);

    User::factory()->create(['role' => 'Receptionist']);

    $this->getJson('/api/v1/public/doctors')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $doctor->id)
        ->assertJsonCount(1, 'data.0.schedules')
        ->assertJsonPath('data.0.schedules.0.day_of_week', 1)
        ->assertJsonPath('data.0.schedules.0.start_time', '09:00:00');
});

test('a public booking request creates a new website lead with a timeline entry', function () {
    $branch = public_branch();
    $doctor = public_doctor();

    $this->postJson('/api/v1/public/booking', [
        'name' => 'Sana Khan',
        'email' => 'sana@example.com',
        'phone' => '+92 322 1112223',
        'service_interest' => 'Cardiology Consultation',
        'preferred_doctor_id' => $doctor->id,
        'preferred_date' => now()->addWeek()->format('Y-m-d'),
        'preferred_time' => '11:00',
        'message' => 'I would like a first consultation.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', Lead::STATUS_NEW);

    $this->assertDatabaseHas('leads', [
        'branch_id' => $branch->id,
        'source' => Lead::SOURCE_WEBSITE,
        'status' => Lead::STATUS_NEW,
        'service_interest' => 'Cardiology Consultation',
    ]);

    $lead = Lead::firstOrFail();

    $this->assertDatabaseHas('communications', [
        'lead_id' => $lead->id,
        'channel' => Communication::CHANNEL_WEBSITE,
        'summary' => 'Website inquiry received',
    ]);
});

test('a booking request rejects an invalid preferred doctor', function () {
    $receptionist = User::factory()->create(['role' => 'Receptionist']);

    $this->postJson('/api/v1/public/booking', [
        'name' => 'Sana Khan',
        'phone' => '+92 322 1112223',
        'preferred_doctor_id' => $receptionist->id,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('preferred_doctor_id');
});

test('a booking request requires a name and phone contact', function () {
    $this->postJson('/api/v1/public/booking', ['email' => 'nobody@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'phone']);
});

test('a public contact inquiry is captured as a website lead', function () {
    public_branch();

    $this->postJson('/api/v1/public/contact', [
        'name' => 'Bilal Ahmed',
        'email' => 'bilal@example.com',
        'phone' => '+92 333 9998877',
        'message' => 'Do you offer evening appointments on weekends?',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', Lead::STATUS_NEW);

    $this->assertDatabaseHas('leads', [
        'source' => Lead::SOURCE_WEBSITE,
        'status' => Lead::STATUS_NEW,
    ]);

    $lead = Lead::firstOrFail();
    expect($lead->notes)->toBe('Do you offer evening appointments on weekends?');
});

test('public booking endpoints are rate limited per visitor', function () {
    public_branch();

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/public/booking', [
            'name' => "Visitor {$i}",
            'phone' => "+92 300 0000{$i}",
        ])->assertCreated();
    }

    $this->postJson('/api/v1/public/booking', [
        'name' => 'Rate limited',
        'phone' => '+92 300 0000999',
    ])->assertStatus(429);
});
