<?php

use App\Events\AppointmentStatusChanged;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\DoctorSchedule;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

pest()->use(RefreshDatabase::class);

function portal_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function portal_patient_user(Patient $patient): User
{
    return User::factory()->create(['role' => 'Patient', 'patient_id' => $patient->id]);
}

function portal_schedule_for(User $doctor, Carbon $date): DoctorSchedule
{
    return DoctorSchedule::factory()
        ->on((string) $date->dayOfWeek, '09:00', '12:00')
        ->create(['doctor_id' => $doctor->id]);
}

test('a patient user can log in through the session flow', function () {
    $patient = Patient::factory()->create();
    $user = portal_patient_user($patient);

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('user.role', 'Patient')
        ->assertJsonPath('user.permissions', fn ($permissions) => in_array('portal.view', $permissions, true));

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('user.role', 'Patient');
});

test('an account without a linked patient record is blocked from the portal', function () {
    $patient = User::factory()->create(['role' => 'Patient', 'patient_id' => null]);

    $this->actingAs($patient)
        ->getJson('/api/v1/portal/appointments')
        ->assertForbidden();
});

test('the portal lists only the signed-in patient appointments', function () {
    $doctor = portal_doctor();
    $scheduledAt = now()->addDays(2)->format('Y-m-d 10:00:00');

    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();
    $userA = portal_patient_user($patientA);

    $appointmentA = Appointment::factory()->create([
        'patient_id' => $patientA->id,
        'doctor_id' => $doctor->id,
        'scheduled_at' => $scheduledAt,
        'status' => 'booked',
    ]);
    Appointment::factory()->create([
        'patient_id' => $patientB->id,
        'doctor_id' => $doctor->id,
        'scheduled_at' => now()->addDays(2)->format('Y-m-d 11:00:00'),
        'status' => 'booked',
    ]);

    $this->actingAs($userA)
        ->getJson('/api/v1/portal/appointments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $appointmentA->id);
});

test('portal invoices are scoped and pdf download is IDOR-safe', function () {
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();
    $userA = portal_patient_user($patientA);

    $invoiceA = Invoice::factory()->create(['patient_id' => $patientA->id, 'status' => 'paid']);
    $invoiceB = Invoice::factory()->create(['patient_id' => $patientB->id, 'status' => 'paid']);

    $this->actingAs($userA)
        ->getJson('/api/v1/portal/invoices')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $invoiceA->id);

    $this->actingAs($userA)
        ->get("/api/v1/portal/invoices/{$invoiceB->id}/pdf")
        ->assertForbidden();

    $this->actingAs($userA)
        ->get("/api/v1/portal/invoices/{$invoiceA->id}/pdf")
        ->assertOk();
});

test('portal prescriptions are scoped and pdf download is IDOR-safe', function () {
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();
    $userA = portal_patient_user($patientA);

    $prescriptionA = Prescription::factory()->create(['patient_id' => $patientA->id]);
    $prescriptionB = Prescription::factory()->create(['patient_id' => $patientB->id]);

    $this->actingAs($userA)
        ->getJson('/api/v1/portal/prescriptions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $prescriptionA->id);

    $this->actingAs($userA)
        ->get("/api/v1/portal/prescriptions/{$prescriptionB->id}/pdf")
        ->assertForbidden();

    $this->actingAs($userA)
        ->get("/api/v1/portal/prescriptions/{$prescriptionA->id}/pdf")
        ->assertOk();
});

test('a patient can book an appointment only for their own record', function () {
    Event::fake([AppointmentStatusChanged::class]);

    $doctor = portal_doctor();
    $branch = Branch::factory()->create();
    $patient = Patient::factory()->create(['branch_id' => $branch->id]);
    $user = portal_patient_user($patient);
    $date = now()->addDays(8)->startOfDay();
    $dateStr = $date->format('Y-m-d');
    portal_schedule_for($doctor, $date);

    $this->actingAs($user)
        ->postJson('/api/v1/portal/appointments', [
            'doctor_id' => $doctor->id,
            'branch_id' => $branch->id,
            'scheduled_at' => $dateStr.' 09:30:00',
            'reason' => 'Routine follow-up',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'booked')
        ->assertJsonPath('data.reason', 'Routine follow-up');

    $this->assertDatabaseHas('appointments', [
        'patient_id' => $patient->id,
        'status' => 'booked',
    ]);
});

test('consent preferences are stored and read per account', function () {
    $patient = Patient::factory()->create();
    $user = portal_patient_user($patient);

    $this->actingAs($user)
        ->putJson('/api/v1/portal/consent', [
            'appointments' => false,
            'low_stock' => true,
            'security' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.appointments', false);

    $this->actingAs($user)
        ->getJson('/api/v1/portal/consent')
        ->assertOk()
        ->assertJsonPath('data.appointments', false)
        ->assertJsonPath('data.low_stock', true);
});

test('the portal lists only the signed-in patient lab results', function () {
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();
    $userA = portal_patient_user($patientA);

    $resultA = LabOrder::factory()->create([
        'patient_id' => $patientA->id,
        'status' => 'completed',
        'result' => 'Haemoglobin 13.2 g/dL',
        'verification_state' => 'verified',
    ]);
    LabOrder::factory()->create([
        'patient_id' => $patientB->id,
        'status' => 'completed',
        'result' => 'Glucose 8.1 mmol/L',
    ]);

    $this->actingAs($userA)
        ->getJson('/api/v1/portal/lab-results')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $resultA->id)
        ->assertJsonPath('data.0.test_name', $resultA->test_name)
        ->assertJsonPath('data.0.result', 'Haemoglobin 13.2 g/dL')
        ->assertJsonPath('data.0.verification_state', 'verified');
});

test('the portal lists only the signed-in user security events', function () {
    $patient = Patient::factory()->create();
    $user = portal_patient_user($patient);
    $other = User::factory()->create();

    SecurityEvent::factory()->create(['user_id' => $user->id, 'type' => 'login_failed']);
    SecurityEvent::factory()->create(['user_id' => $other->id, 'type' => 'login_failed']);

    $this->actingAs($user)
        ->getJson('/api/v1/portal/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'login_failed')
        ->assertJsonPath('data.0.read', false);
});
