<?php

use App\Mail\ClinicNotificationMail;
use App\Models\User;
use App\Services\Messaging\EmailDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

pest()->use(RefreshDatabase::class);

/**
 * The email driver is addressed by email address, not phone number, and only
 * reports itself enabled when the clinic has both switched it on and pointed
 * Laravel's mailer at a transport that actually delivers.
 */
function emailDriver(array $overrides = []): EmailDriver
{
    return new EmailDriver($overrides + [
        'enabled' => true,
        'mailer' => 'smtp',
        'from_address' => 'no-reply@clinic.example',
        'from_name' => 'Al-Shifa Clinic',
    ]);
}

test('the email driver reports the email channel name', function () {
    expect(emailDriver()->name())->toBe('email');
});

test('the email driver is enabled only with the flag, a from address and a delivering mailer', function () {
    expect(emailDriver()->isEnabled())->toBeTrue();

    expect(emailDriver(['enabled' => false])->isEnabled())->toBeFalse();
    expect(emailDriver(['from_address' => ''])->isEnabled())->toBeFalse();
});

test('the email driver refuses to report itself enabled for non-delivering mailers', function () {
    // "log" and "array" write to a file or an array instead of delivering, so a
    // clinic must never see a message recorded as sent when it was swallowed.
    expect(emailDriver(['mailer' => 'log'])->isEnabled())->toBeFalse();
    expect(emailDriver(['mailer' => 'array'])->isEnabled())->toBeFalse();
    expect(emailDriver(['mailer' => 'smtp'])->isEnabled())->toBeTrue();
});

test('the email driver falls back to the application mail config when the driver config omits it', function () {
    config([
        'mail.default' => 'smtp',
        'mail.from.address' => 'clinic@example.test',
        'mail.from.name' => 'Fallback Clinic',
    ]);

    $driver = new EmailDriver(['enabled' => true]);

    expect($driver->isEnabled())->toBeTrue();
});

test('a null from address in the driver config defers to the application mail config', function () {
    config([
        'mail.default' => 'smtp',
        'mail.from.address' => 'clinic@example.test',
    ]);

    // config/messaging.php reads env('MAIL_FROM_ADDRESS'), which is null when
    // unset, so the driver must not treat a null entry as "no address".
    expect((new EmailDriver(['enabled' => true, 'from_address' => null]))->isEnabled())->toBeTrue();
});

test('the email driver only handles the email channel', function () {
    $driver = emailDriver();

    expect($driver->handlesChannel('email'))->toBeTrue()
        ->and($driver->handlesChannel('sms'))->toBeFalse()
        ->and($driver->handlesChannel('whatsapp'))->toBeFalse()
        ->and($driver->handlesChannel('appointments'))->toBeFalse();
});

test('the email driver resolves the recipient from the user email address', function () {
    $user = User::factory()->create(['email' => 'patient@example.test', 'phone' => '+15551230000']);

    expect(emailDriver()->recipientFor($user))->toBe('patient@example.test');
});

test('an explicit recipient overrides the user email address', function () {
    $user = User::factory()->create(['email' => 'patient@example.test']);

    expect(emailDriver()->recipientFor($user, 'care@example.test'))->toBe('care@example.test');
});

test('a user with a blank email address resolves to no recipient', function () {
    $user = User::factory()->create(['email' => '']);

    expect(emailDriver()->recipientFor($user))->toBeNull();
});

test('the email driver sends the rendered body and subject through the configured mailer', function () {
    Mail::fake();

    $delivered = emailDriver()->send(
        'patient@example.test',
        'Dear Ayesha, your appointment is on 2026-10-01.',
        ['subject' => 'Appointment confirmed'],
    );

    expect($delivered)->toBeTrue();

    Mail::assertSent(ClinicNotificationMail::class, function (ClinicNotificationMail $mail): bool {
        return $mail->hasTo('patient@example.test')
            && $mail->hasSubject('Appointment confirmed');
    });
});

test('the email driver falls back to the title when no subject is supplied', function () {
    Mail::fake();

    emailDriver()->send('patient@example.test', 'Body text.', ['title' => 'Follow-up due']);

    Mail::assertSent(ClinicNotificationMail::class, fn (ClinicNotificationMail $mail): bool => $mail->hasSubject('Follow-up due'));
});

test('the email driver uses a neutral subject when neither subject nor title is supplied', function () {
    Mail::fake();

    emailDriver()->send('patient@example.test', 'Body text.');

    Mail::assertSent(ClinicNotificationMail::class, fn (ClinicNotificationMail $mail): bool => $mail->hasSubject('Clinic notification'));
});

test('a transport failure is reported as an undelivered message rather than throwing', function () {
    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('SMTP connection refused'));

    expect(emailDriver()->send('patient@example.test', 'Body text.', ['subject' => 'Nope']))->toBeFalse();
});

test('the rendered email body is escaped and auto-directioned for Urdu and English', function () {
    Mail::fake();

    emailDriver()->send('patient@example.test', "Line one <script>alert('x')</script>\nLine two", ['subject' => 'Report']);

    Mail::assertSent(ClinicNotificationMail::class, function (ClinicNotificationMail $mail): bool {
        $rendered = $mail->render();

        // The template body originates from the database, so any markup in it
        // must be escaped rather than injected into the email.
        expect($rendered)->not->toContain('<script>')
            ->and($rendered)->toContain('&lt;script&gt;');

        // dir="auto" lets the client pick RTL for an Urdu body and LTR for an
        // English one from the same template (DESIGN §19).
        expect($rendered)->toContain('dir="auto"');

        return true;
    });
});
