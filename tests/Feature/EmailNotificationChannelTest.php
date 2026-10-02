<?php

use App\Mail\ClinicNotificationMail;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Messaging\EmailDriver;
use App\Services\Messaging\TwilioSmsDriver;
use App\Services\Messaging\WhatsAppCloudApiDriver;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

pest()->use(RefreshDatabase::class);

/**
 * Phone channels are always present in messaging.php. These helpers keep the
 * existing two drivers switched off so an assertion only ever describes the
 * behaviour under test.
 */
function et_disable_phone_channels(): void
{
    config([
        'messaging.drivers.sms.driver' => TwilioSmsDriver::class,
        'messaging.drivers.sms.enabled' => false,
        'messaging.drivers.whatsapp.driver' => WhatsAppCloudApiDriver::class,
        'messaging.drivers.whatsapp.enabled' => false,
    ]);
}

function et_enable_email(array $overrides = []): void
{
    config(array_replace([
        'messaging.drivers.sms.driver' => TwilioSmsDriver::class,
        'messaging.drivers.sms.enabled' => false,
        'messaging.drivers.whatsapp.driver' => WhatsAppCloudApiDriver::class,
        'messaging.drivers.whatsapp.enabled' => false,
        'messaging.drivers.email.driver' => EmailDriver::class,
        'messaging.drivers.email.enabled' => true,
        'messaging.drivers.email.mailer' => 'smtp',
        'messaging.drivers.email.from_address' => 'no-reply@clinic.example',
        'messaging.drivers.email.from_name' => 'Al-Shifa Clinic',
    ], $overrides));
}

function et_email_template(array $overrides = []): NotificationTemplate
{
    return NotificationTemplate::factory()->approved()->create($overrides + [
        'channel' => NotificationTemplate::CHANNEL_EMAIL,
        'name' => 'Appointment reminder',
        'subject' => 'Your appointment on {{appointment_date}}',
        'body' => 'Dear {{patient_name}}, you are booked on {{appointment_date}} at {{appointment_time}}.',
    ]);
}

test('an approved email template is delivered to the patient email address', function () {
    Mail::fake();
    et_enable_email();

    $user = User::factory()->create(['email' => 'patient@example.test', 'phone' => null]);
    $template = et_email_template();

    $deliveries = app(NotificationService::class)->sendFromTemplate($user, $template, [
        'patient_name' => 'Ayesha',
        'appointment_date' => '2026-10-01',
        'appointment_time' => '10:30 AM',
    ]);

    expect($deliveries)->toBe(['email' => 'sent']);

    Mail::assertSent(ClinicNotificationMail::class, function (ClinicNotificationMail $mail): bool {
        return $mail->hasTo('patient@example.test')
            && $mail->hasSubject('Your appointment on 2026-10-01')
            && str_contains($mail->render(), 'Dear Ayesha, you are booked on 2026-10-01 at 10:30 AM.');
    });

    $this->assertDatabaseHas('notification_logs', [
        'user_id' => $user->id,
        'channel' => NotificationTemplate::CHANNEL_EMAIL,
        'template_id' => $template->id,
        'title' => 'Your appointment on 2026-10-01',
        'status' => 'sent',
    ]);
});

test('the email channel is never pushed through the phone transports', function () {
    Mail::fake();
    et_enable_email();

    // A phone channel with both phone drivers live: the email driver must stay
    // out of it, and the phone drivers must stay out of the email channel.
    config([
        'messaging.drivers.sms.enabled' => true,
        'messaging.drivers.sms.sid' => 'AC-test',
        'messaging.drivers.sms.token' => 'token',
        'messaging.drivers.sms.from' => '+15550000000',
    ]);
    Http::fake(['api.twilio.com/*' => Http::response([], 201)]);

    $user = User::factory()->create(['email' => 'patient@example.test', 'phone' => '+15559990000']);

    $emailDeliveries = app(NotificationService::class)->send($user, 'email', 'Subject line', 'Body text.');

    expect($emailDeliveries)->toBe(['email' => 'sent']);
    Mail::assertSentCount(1);
    Http::assertNothingSent();

    $smsDeliveries = app(NotificationService::class)->send($user, 'appointments', 'Reminder', 'See you soon.');

    expect($smsDeliveries)->toBe(['sms' => 'sent', 'whatsapp' => 'logged']);
    Mail::assertSentCount(1);
});

test('a disabled email driver falls back to a logged notification row', function () {
    Mail::fake();
    et_enable_email(['messaging.drivers.email.enabled' => false]);

    $user = User::factory()->create(['email' => 'patient@example.test', 'phone' => null]);
    $template = et_email_template();

    $deliveries = app(NotificationService::class)->sendFromTemplate($user, $template, [
        'patient_name' => 'Ayesha',
        'appointment_date' => '2026-10-01',
        'appointment_time' => '10:30 AM',
    ]);

    expect($deliveries)->toBe(['email' => 'logged']);
    Mail::assertNothingSent();

    $this->assertDatabaseHas('notification_logs', [
        'user_id' => $user->id,
        'channel' => NotificationTemplate::CHANNEL_EMAIL,
        'status' => 'logged',
    ]);
});

test('an email driver pointed at a non-delivering mailer falls back to logged', function () {
    Mail::fake();
    et_enable_email(['messaging.drivers.email.mailer' => 'log']);

    $user = User::factory()->create(['email' => 'patient@example.test', 'phone' => null]);
    $template = et_email_template();

    $deliveries = app(NotificationService::class)->sendFromTemplate($user, $template, [
        'patient_name' => 'Ayesha',
        'appointment_date' => '2026-10-01',
        'appointment_time' => '10:30 AM',
    ]);

    // The message must not be reported as sent when the mailer only writes to a
    // log file: the clinic would otherwise believe a patient was notified.
    expect($deliveries)->toBe(['email' => 'logged']);
    Mail::assertNothingSent();
    $this->assertDatabaseHas('notification_logs', ['user_id' => $user->id, 'status' => 'logged']);
});

test('an email with no address is skipped as a missing recipient', function () {
    Mail::fake();
    et_enable_email();

    $user = User::factory()->create(['email' => '', 'phone' => '+15559990001']);

    $deliveries = app(NotificationService::class)->send($user, 'email', 'Subject', 'Body.');

    expect($deliveries)->toBe(['skipped' => 'missing_recipient']);
    Mail::assertNothingSent();
    $this->assertDatabaseHas('notification_logs', ['user_id' => $user->id, 'status' => 'skipped']);
});

test('an opted-out user never receives an email', function () {
    Mail::fake();
    et_enable_email();

    $user = User::factory()->create([
        'email' => 'patient@example.test',
        'phone' => null,
        'notification_preferences' => [NotificationTemplate::CHANNEL_EMAIL => false],
    ]);
    $template = et_email_template();

    $deliveries = app(NotificationService::class)->sendFromTemplate($user, $template, []);

    expect($deliveries)->toBe(['skipped' => NotificationTemplate::CHANNEL_EMAIL]);
    Mail::assertNothingSent();
    $this->assertDatabaseHas('notification_logs', ['user_id' => $user->id, 'status' => 'skipped']);
});

test('an unapproved email template can never be sent', function () {
    Mail::fake();
    et_enable_email();

    $user = User::factory()->create(['email' => 'patient@example.test', 'phone' => null]);
    $template = NotificationTemplate::factory()->create([
        'channel' => NotificationTemplate::CHANNEL_EMAIL,
        'name' => 'Pending receipt email',
        'subject' => 'Receipt',
        'body' => 'Thanks {{patient_name}}.',
        'is_approved' => false,
    ]);

    expect(fn () => app(NotificationService::class)->sendFromTemplate($user, $template, ['patient_name' => 'Sara']))
        ->toThrow(ValidationException::class);

    Mail::assertNothingSent();
    expect(NotificationLog::count())->toBe(0);
});

test('an email transport error is recorded as a failed delivery', function () {
    et_enable_email();
    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('SMTP refused'));

    $user = User::factory()->create(['email' => 'patient@example.test', 'phone' => null]);
    $template = et_email_template();

    $deliveries = app(NotificationService::class)->sendFromTemplate($user, $template, [
        'patient_name' => 'Ayesha',
        'appointment_date' => '2026-10-01',
        'appointment_time' => '10:30 AM',
    ]);

    expect($deliveries)->toBe(['email' => 'failed']);
    $this->assertDatabaseHas('notification_logs', ['user_id' => $user->id, 'status' => 'failed']);
});

test('an email body with partial placeholder data leaves unknown tokens intact', function () {
    Mail::fake();
    et_enable_email();

    $user = User::factory()->create(['email' => 'patient@example.test', 'phone' => null]);
    $template = et_email_template([
        'subject' => 'Visit on {{appointment_date}}',
        'body' => 'Hello {{patient_name}}, see you on {{appointment_date}} with {{doctor_name}}.',
    ]);

    app(NotificationService::class)->sendFromTemplate($user, $template, ['patient_name' => 'Ayesha']);

    Mail::assertSent(ClinicNotificationMail::class, fn (ClinicNotificationMail $mail): bool => str_contains(
        $mail->render(),
        'Hello Ayesha, see you on {{appointment_date}} with {{doctor_name}}.'
    ));
});

test('an Urdu email body is delivered and rendered for right-to-left reading', function () {
    Mail::fake();
    et_enable_email();

    $user = User::factory()->create(['email' => 'patient@example.test', 'phone' => null]);
    $template = et_email_template([
        'subject' => 'آپ کا اپائنٹمنٹ {{appointment_date}} کو ہے',
        'body' => 'محترم {{patient_name}}، آپ کا اپائنٹمنٹ {{appointment_date}} کو {{appointment_time}} پر ہے۔',
    ]);

    $deliveries = app(NotificationService::class)->sendFromTemplate($user, $template, [
        'patient_name' => 'عائشہ',
        'appointment_date' => '2026-10-01',
        'appointment_time' => '10:30 AM',
    ]);

    expect($deliveries)->toBe(['email' => 'sent']);

    Mail::assertSent(ClinicNotificationMail::class, function (ClinicNotificationMail $mail): bool {
        $rendered = $mail->render();

        expect($rendered)->toContain('محترم عائشہ، آپ کا اپائنٹمنٹ 2026-10-01 کو 10:30 AM پر ہے۔')
            ->and($rendered)->toContain('dir="auto"');

        return true;
    });
});
