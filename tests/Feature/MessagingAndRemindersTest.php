<?php

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Messaging\TwilioSmsDriver;
use App\Services\Messaging\WhatsAppCloudApiDriver;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

pest()->use(RefreshDatabase::class);

/**
 * Create a Patient portal account linked to an actual patient record, as the
 * real database seeder does for patient@clinic.com.
 */
function portalAccount(Patient $patient, array $overrides = []): User
{
    return User::factory()->create([
        'role' => 'Patient',
        'patient_id' => $patient->id,
        'phone' => $patient->phone,
        ...$overrides,
    ]);
}

function enableTwilio(): void
{
    config([
        'messaging.drivers.sms.driver' => TwilioSmsDriver::class,
        'messaging.drivers.sms.enabled' => true,
        'messaging.drivers.sms.sid' => 'AC-test-sid',
        'messaging.drivers.sms.token' => 'test-token',
        'messaging.drivers.sms.from' => '+15550000111',
        'messaging.drivers.whatsapp.driver' => WhatsAppCloudApiDriver::class,
        'messaging.drivers.whatsapp.enabled' => false,
    ]);
}

function enableWhatsApp(): void
{
    config([
        'messaging.drivers.whatsapp.driver' => WhatsAppCloudApiDriver::class,
        'messaging.drivers.whatsapp.enabled' => true,
        'messaging.drivers.whatsapp.token' => 'test-wa-token',
        'messaging.drivers.whatsapp.phone_number_id' => '123456789',
        'messaging.drivers.whatsapp.api_version' => 'v19.0',
        'messaging.drivers.sms.driver' => TwilioSmsDriver::class,
        'messaging.drivers.sms.enabled' => false,
    ]);
}

test('notification service delivers via Twilio SMS when configured and consented', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM-test'], 201)]);
    enableTwilio();

    $user = User::factory()->create(['phone' => '+1 (555) 210-0041']);

    $deliveries = app(NotificationService::class)->send($user, 'appointments', 'Reminder', 'Your appointment is tomorrow.');

    expect($deliveries)->toBe(['sms' => 'sent', 'whatsapp' => 'logged']);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/2010-04-01/Accounts/AC-test-sid/Messages.json')
            && $request['To'] === '+1 (555) 210-0041'
            && $request['From'] === '+15550000111'
            && $request['Body'] === 'Your appointment is tomorrow.';
    });

    $this->assertDatabaseHas('notification_logs', [
        'user_id' => $user->id,
        'channel' => 'appointments',
        'title' => 'Reminder',
        'status' => 'sent',
    ]);
});

test('notification service delivers via WhatsApp Cloud API when configured', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid-test']]], 200)]);
    enableWhatsApp();

    $user = User::factory()->create(['phone' => '+15552100042']);

    $deliveries = app(NotificationService::class)->send($user, 'appointments', 'Reminder', 'See you at 9am.');

    expect($deliveries)->toBe(['sms' => 'logged', 'whatsapp' => 'sent']);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/v19.0/123456789/messages')
            && $request->hasHeader('Authorization', 'Bearer test-wa-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '+15552100042'
            && $request['text']['body'] === 'See you at 9am.';
    });
});

test('unconfigured drivers fall back to a logged audit record', function () {
    $user = User::factory()->create(['phone' => '+15552100043']);

    config([
        'messaging.drivers.sms.driver' => TwilioSmsDriver::class,
        'messaging.drivers.sms.enabled' => false,
        'messaging.drivers.whatsapp.driver' => WhatsAppCloudApiDriver::class,
        'messaging.drivers.whatsapp.enabled' => false,
    ]);

    $deliveries = app(NotificationService::class)->send($user, 'appointments', 'Reminder', 'Logged only.');

    expect($deliveries)->toBe(['sms' => 'logged', 'whatsapp' => 'logged']);
    Http::assertNothingSent();

    $this->assertDatabaseHas('notification_logs', [
        'user_id' => $user->id,
        'channel' => 'appointments',
        'status' => 'logged',
    ]);
});

test('opted-out consent and missing recipients both skip delivery', function () {
    Http::fake(['api.twilio.com/*' => Http::response([], 201)]);
    enableTwilio();

    $optedOut = User::factory()->create([
        'phone' => '+15552100044',
        'notification_preferences' => ['appointments' => false],
    ]);

    $deliveries = app(NotificationService::class)->send($optedOut, 'appointments', 'Reminder', 'Skip me.');

    expect($deliveries)->toBe(['skipped' => 'appointments']);
    $this->assertDatabaseHas('notification_logs', ['user_id' => $optedOut->id, 'status' => 'skipped']);

    $noPhone = User::factory()->create(['phone' => null]);

    $deliveries = app(NotificationService::class)->send($noPhone, 'appointments', 'Reminder', 'No destination.');

    expect($deliveries)->toBe(['skipped' => 'missing_recipient']);
    $this->assertDatabaseHas('notification_logs', ['user_id' => $noPhone->id, 'status' => 'skipped']);

    Http::assertNothingSent();
});

test('a provider rejection is recorded as a failed delivery', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'Blocked'], 400)]);
    enableTwilio();

    $user = User::factory()->create(['phone' => '+15552100045']);

    $deliveries = app(NotificationService::class)->send($user, 'appointments', 'Reminder', 'Boom.');

    expect($deliveries)->toBe(['sms' => 'failed', 'whatsapp' => 'logged']);
    $this->assertDatabaseHas('notification_logs', ['user_id' => $user->id, 'status' => 'failed']);
});

test('the reminder command only messages consented in-window appointments', function () {
    Http::fake(['api.twilio.com/*' => Http::response([], 201)]);
    enableTwilio();

    $patient = Patient::factory()->create(['phone' => '+15552100046']);
    portalAccount($patient);

    Appointment::factory()->create([
        'patient_id' => $patient->id,
        'status' => 'booked',
        'scheduled_at' => now()->addHours(4)->format('Y-m-d H:i:s'),
    ]);

    Appointment::factory()->create([
        'patient_id' => $patient->id,
        'status' => 'completed',
        'scheduled_at' => now()->addHours(6)->format('Y-m-d H:i:s'),
    ]);

    Appointment::factory()->create([
        'patient_id' => $patient->id,
        'status' => 'booked',
        'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
    ]);

    $this->artisan('clinic:send-reminders')->assertExitCode(0);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        return $request['To'] === '+15552100046'
            && str_contains($request['Body'], 'reminder');
    });
});

test('reminders skip opted-out patients and patients without a portal account', function () {
    Http::fake(['api.twilio.com/*' => Http::response([], 201)]);
    enableTwilio();

    $optedOut = Patient::factory()->create(['phone' => '+15552100047']);
    portalAccount($optedOut, ['notification_preferences' => ['appointments' => false]]);

    Appointment::factory()->create([
        'patient_id' => $optedOut->id,
        'status' => 'booked',
        'scheduled_at' => now()->addHours(2)->format('Y-m-d H:i:s'),
    ]);

    $noAccount = Patient::factory()->create(['phone' => '+15552100048']);

    Appointment::factory()->create([
        'patient_id' => $noAccount->id,
        'status' => 'booked',
        'scheduled_at' => now()->addHours(2)->format('Y-m-d H:i:s'),
    ]);

    $this->artisan('clinic:send-reminders')->assertExitCode(0);

    Http::assertNothingSent();
});

test('the patient portal only exposes the signed-in patient records', function () {
    Http::fake();
    config(['messaging.drivers.whatsapp.enabled' => false, 'messaging.drivers.sms.enabled' => false]);

    $alice = Patient::factory()->create(['phone' => '+15552100049']);
    $bob = Patient::factory()->create(['phone' => '+15552100050']);

    $aliceUser = portalAccount($alice);
    portalAccount($bob);

    Appointment::factory()->create([
        'patient_id' => $alice->id,
        'status' => 'booked',
        'scheduled_at' => now()->addHours(6)->format('Y-m-d H:i:s'),
    ]);

    Appointment::factory()->create([
        'patient_id' => $bob->id,
        'status' => 'booked',
        'scheduled_at' => now()->addHours(6)->format('Y-m-d H:i:s'),
    ]);

    $aliceInvoice = Invoice::factory()->create(['patient_id' => $alice->id]);
    Invoice::factory()->create(['patient_id' => $bob->id]);

    $alicePrescription = Prescription::factory()->create(['patient_id' => $alice->id]);
    Prescription::factory()->create(['patient_id' => $bob->id]);

    $this->actingAs($aliceUser)
        ->getJson('/api/v1/portal/appointments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.doctor_name', $alice->appointments->first()->doctor->name);

    $this->actingAs($aliceUser)
        ->getJson('/api/v1/portal/invoices')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.invoice_number', $aliceInvoice->invoice_number);

    $this->actingAs($aliceUser)
        ->getJson('/api/v1/portal/prescriptions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.prescription_number', $alicePrescription->prescription_number);
});

test('portal PDFs are downloadable only by the owning patient', function () {
    Http::fake();
    config(['messaging.drivers.whatsapp.enabled' => false, 'messaging.drivers.sms.enabled' => false]);

    $alice = Patient::factory()->create();
    $bob = Patient::factory()->create();
    $aliceUser = portalAccount($alice);

    $aliceInvoice = Invoice::factory()->create(['patient_id' => $alice->id]);
    $bobInvoice = Invoice::factory()->create(['patient_id' => $bob->id]);
    $alicePrescription = Prescription::factory()->create(['patient_id' => $alice->id]);
    $bobPrescription = Prescription::factory()->create(['patient_id' => $bob->id]);

    $this->actingAs($aliceUser)
        ->get("/api/v1/portal/invoices/{$aliceInvoice->id}/pdf")
        ->assertOk()
        ->assertHeader('Content-Type');

    $this->actingAs($aliceUser)
        ->get("/api/v1/portal/prescriptions/{$alicePrescription->id}/pdf")
        ->assertOk();

    $this->actingAs($aliceUser)
        ->get("/api/v1/portal/invoices/{$bobInvoice->id}/pdf")
        ->assertForbidden();

    $this->actingAs($aliceUser)
        ->get("/api/v1/portal/prescriptions/{$bobPrescription->id}/pdf")
        ->assertForbidden();
});

test('portal consent defaults to enabled and can be updated', function () {
    $patient = Patient::factory()->create();
    $user = portalAccount($patient);

    $this->actingAs($user)
        ->getJson('/api/v1/portal/consent')
        ->assertOk()
        ->assertJson(['data' => ['appointments' => true, 'low_stock' => true, 'security' => true]]);

    $this->actingAs($user)
        ->putJson('/api/v1/portal/consent', [
            'appointments' => false,
            'low_stock' => true,
            'security' => false,
        ])
        ->assertOk()
        ->assertJson(['data' => ['appointments' => false, 'low_stock' => true, 'security' => false]]);

    expect($user->refresh()->consentsTo('appointments'))->toBeFalse()
        ->and($user->refresh()->consentsTo('low_stock'))->toBeTrue();
});

test('staff without portal permissions cannot reach the patient portal', function () {
    $admin = User::factory()->create(['role' => 'Admin']);

    $this->actingAs($admin)
        ->getJson('/api/v1/portal/invoices')
        ->assertForbidden();
});
