<?php

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Messaging\TwilioSmsDriver;
use App\Services\Messaging\WhatsAppCloudApiDriver;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

pest()->use(RefreshDatabase::class);

function wt_enable_whatsapp(): void
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

function wt_enable_twilio(): void
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

function wt_approved_template(array $overrides = []): NotificationTemplate
{
    return NotificationTemplate::factory()->approved()->create($overrides);
}

test('an approved WhatsApp template is delivered as a Meta template message', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid-tpl']]], 200)]);
    wt_enable_whatsapp();

    $user = User::factory()->create(['phone' => '+15552100061']);
    $template = wt_approved_template([
        'channel' => NotificationTemplate::CHANNEL_WHATSAPP,
        'name' => 'Appointment reminder',
        'body' => 'Hello {{patient_name}}, your appointment with {{doctor_name}} is on {{appointment_date}}.',
    ]);

    $deliveries = app(NotificationService::class)->sendFromTemplate(
        $user,
        $template,
        ['patient_name' => 'Ayesha', 'doctor_name' => 'Dr. Noor', 'appointment_date' => '2026-10-01'],
        whatsappTemplateName: 'appointment_reminder',
        whatsappTemplateLanguage: 'en',
        whatsappTemplateParameters: ['Ayesha', 'Dr. Noor', '2026-10-01'],
    );

    expect($deliveries)->toBe(['sms' => 'logged', 'whatsapp' => 'sent']);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/v19.0/123456789/messages')
            && $request->hasHeader('Authorization', 'Bearer test-wa-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '+15552100061'
            && $request['type'] === 'template'
            && $request['template']['name'] === 'appointment_reminder'
            && $request['template']['language']['code'] === 'en'
            && $request['template']['components'] === [[
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => 'Ayesha'],
                    ['type' => 'text', 'text' => 'Dr. Noor'],
                    ['type' => 'text', 'text' => '2026-10-01'],
                ],
            ]];
    });

    $this->assertDatabaseHas('notification_logs', [
        'user_id' => $user->id,
        'channel' => 'whatsapp',
        'template_id' => $template->id,
        'title' => 'Appointment reminder',
        'status' => 'sent',
    ]);
});

test('a WhatsApp template without parameters omits the components block', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid-tpl-simple']]], 200)]);
    wt_enable_whatsapp();

    $user = User::factory()->create(['phone' => '+15552100062']);
    $template = wt_approved_template([
        'channel' => NotificationTemplate::CHANNEL_WHATSAPP,
        'name' => 'System notice',
        'body' => 'Our clinic will be closed on {{date}} for maintenance.',
    ]);

    app(NotificationService::class)->sendFromTemplate(
        $user,
        $template,
        ['date' => '2026-11-01'],
        whatsappTemplateName: 'system_notice',
    );

    Http::assertSent(function (Request $request): bool {
        return $request['type'] === 'template'
            && $request['template']['name'] === 'system_notice'
            && $request['template']['language']['code'] === 'en'
            && ! array_key_exists('components', $request['template']);
    });
});

test('template metadata still delivers free-text SMS when WhatsApp is disabled', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM-test'], 201)]);
    wt_enable_twilio();

    $user = User::factory()->create(['phone' => '+15552100063']);
    $template = wt_approved_template([
        'channel' => NotificationTemplate::CHANNEL_SMS,
        'name' => 'Appointment reminder',
        'body' => 'Reminder: {{patient_name}}, your appointment is on {{appointment_date}}.',
    ]);

    $deliveries = app(NotificationService::class)->sendFromTemplate(
        $user,
        $template,
        ['patient_name' => 'Usman', 'appointment_date' => '2026-10-05'],
        whatsappTemplateName: 'appointment_reminder',
        whatsappTemplateParameters: ['Usman', '2026-10-05'],
    );

    expect($deliveries)->toBe(['sms' => 'sent', 'whatsapp' => 'logged']);
    Http::assertSentCount(1);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'api.twilio.com')
            && $request['To'] === '+15552100063'
            && $request['Body'] === 'Reminder: Usman, your appointment is on 2026-10-05.';
    });
});

test('template sends fall back to logging when all drivers are unconfigured', function () {
    $user = User::factory()->create(['phone' => '+15552100064']);
    $template = wt_approved_template();

    config([
        'messaging.drivers.sms.driver' => TwilioSmsDriver::class,
        'messaging.drivers.sms.enabled' => false,
        'messaging.drivers.whatsapp.driver' => WhatsAppCloudApiDriver::class,
        'messaging.drivers.whatsapp.enabled' => false,
    ]);

    $deliveries = app(NotificationService::class)->sendFromTemplate(
        $user,
        $template,
        ['patient_name' => 'Zara', 'doctor_name' => 'Dr. Noor'],
        whatsappTemplateName: 'appointment_reminder',
    );

    expect($deliveries)->toBe(['sms' => 'logged', 'whatsapp' => 'logged']);
    Http::assertNothingSent();
    $this->assertDatabaseHas('notification_logs', [
        'user_id' => $user->id,
        'template_id' => $template->id,
        'status' => 'logged',
    ]);
});

test('a Meta template rejection is recorded as a failed delivery', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template not approved']], 400)]);
    wt_enable_whatsapp();

    $user = User::factory()->create(['phone' => '+15552100065']);
    $template = wt_approved_template([
        'channel' => NotificationTemplate::CHANNEL_WHATSAPP,
        'name' => 'Appointment reminder',
    ]);

    $deliveries = app(NotificationService::class)->sendFromTemplate(
        $user,
        $template,
        ['patient_name' => 'Hira'],
        whatsappTemplateName: 'appointment_reminder',
        whatsappTemplateParameters: ['Hira'],
    );

    expect($deliveries)->toBe(['sms' => 'logged', 'whatsapp' => 'failed']);
    $this->assertDatabaseHas('notification_logs', ['user_id' => $user->id, 'channel' => 'whatsapp', 'status' => 'failed']);
});
