<?php

use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

pest()->use(RefreshDatabase::class);

function nt_user(string $role = 'Admin'): User
{
    return User::factory()->create(['role' => $role]);
}

function nt_template(array $overrides = []): NotificationTemplate
{
    return NotificationTemplate::factory()->create($overrides);
}

function nt_approved_template(array $overrides = [], ?User $approver = null): NotificationTemplate
{
    $approver ??= nt_user();

    return NotificationTemplate::factory()->create($overrides + [
        'is_approved' => true,
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ]);
}

test('an admin can create a draft template and it is audited', function () {
    $admin = nt_user();

    $this->actingAs($admin)
        ->postJson('/api/v1/notification-templates', [
            'channel' => 'sms',
            'name' => 'Appointment reminder',
            'body' => 'Dear {{patient_name}}, see you at {{appointment_time}}.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.name', 'Appointment reminder')
        ->assertJsonPath('data.is_approved', false)
        ->assertJsonPath('data.created_by', $admin->name);

    $template = NotificationTemplate::firstOrFail();

    expect($template->is_approved)->toBeFalse()
        ->and($template->created_by)->toBe($admin->id)
        ->and(AuditLog::query()->where('action', 'notification_template.created')->count())->toBe(1);
});

test('an email template stores a subject line', function () {
    $admin = nt_user();

    $this->actingAs($admin)
        ->postJson('/api/v1/notification-templates', [
            'channel' => 'email',
            'name' => 'Payment receipt',
            'subject' => 'Payment received for {{invoice_number}}',
            'body' => 'Thank you, {{patient_name}}.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.subject', 'Payment received for {{invoice_number}}');
});

test('template creation validates channel, name and body', function () {
    $admin = nt_user();

    $this->actingAs($admin)
        ->postJson('/api/v1/notification-templates', ['name' => 'Missing body', 'channel' => 'sms'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body']);

    $this->actingAs($admin)
        ->postJson('/api/v1/notification-templates', ['name' => 'Bad channel', 'channel' => 'carrier-pigeon', 'body' => 'hi'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel']);
});

test('template names are unique per channel but reusable across channels', function () {
    $admin = nt_user();

    $payload = ['name' => 'Reminder', 'channel' => 'sms', 'body' => 'hello {{patient_name}}'];

    $this->actingAs($admin)->postJson('/api/v1/notification-templates', $payload)->assertCreated();
    $this->actingAs($admin)->postJson('/api/v1/notification-templates', $payload)->assertUnprocessable();
    $this->actingAs($admin)->postJson('/api/v1/notification-templates', [...$payload, 'channel' => 'email'])->assertCreated();

    expect(NotificationTemplate::count())->toBe(2);
});

test('templates are listed and filterable by channel and approval status', function () {
    $admin = nt_user();
    nt_template(['channel' => 'sms', 'name' => 'SMS one']);
    nt_approved_template(['channel' => 'email', 'name' => 'Email one'], $admin);

    $this->actingAs($admin)
        ->getJson('/api/v1/notification-templates?channel=sms')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'SMS one');

    $this->actingAs($admin)
        ->getJson('/api/v1/notification-templates?status=approved')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Email one');

    $this->actingAs($admin)
        ->getJson('/api/v1/notification-templates')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('a template can be shown and updated', function () {
    $admin = nt_user();
    $template = nt_template(['name' => 'Old name']);

    $this->actingAs($admin)
        ->getJson("/api/v1/notification-templates/{$template->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Old name');

    $this->actingAs($admin)
        ->putJson("/api/v1/notification-templates/{$template->id}", [
            'channel' => 'sms',
            'name' => 'New name',
            'body' => 'Updated body {{patient_name}}',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New name')
        ->assertJsonPath('data.body', 'Updated body {{patient_name}}');

    expect(AuditLog::query()->where('action', 'notification_template.updated')->count())->toBe(1);
});

test('editing an approved template returns it to pending for re-review', function () {
    $admin = nt_user();
    $template = nt_approved_template(['name' => 'Reviewed copy'], $admin);

    $this->actingAs($admin)
        ->putJson("/api/v1/notification-templates/{$template->id}", [
            'channel' => 'sms',
            'name' => 'Reviewed copy edited',
            'body' => 'changed {{patient_name}}',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_approved', false)
        ->assertJsonPath('data.approved_by', null)
        ->assertJsonPath('data.approved_at', null);
});

test('approving a template records the actor and time; rejecting resets it', function () {
    $admin = nt_user();
    $template = nt_template(['name' => 'Pending copy']);

    $this->actingAs($admin)
        ->postJson("/api/v1/notification-templates/{$template->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.is_approved', true)
        ->assertJsonPath('data.approved_by', $admin->name);

    $template->refresh();

    expect($template->approved_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'notification_template.approved')->count())->toBe(1);

    $this->actingAs($admin)
        ->postJson("/api/v1/notification-templates/{$template->id}/reject")
        ->assertOk()
        ->assertJsonPath('data.is_approved', false);

    $template->refresh();

    expect($template->approved_by)->toBeNull()
        ->and($template->approved_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'notification_template.rejected')->count())->toBe(1);
});

test('approved templates cannot be deleted until they are rejected', function () {
    $admin = nt_user();
    $approved = nt_approved_template(['name' => 'Live copy'], $admin);
    $draft = nt_template(['name' => 'Draft copy']);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/notification-templates/{$approved->id}")
        ->assertUnprocessable();

    expect(NotificationTemplate::find($approved->id))->not->toBeNull();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/notification-templates/{$draft->id}")
        ->assertOk();

    expect(NotificationTemplate::find($draft->id))->toBeNull()
        ->and(NotificationTemplate::withTrashed()->find($draft->id))->not->toBeNull();
});

test('templates require the notifications permission', function () {
    $outsider = User::factory()->create(['role' => 'Patient']);

    $this->actingAs($outsider)
        ->getJson('/api/v1/notification-templates')
        ->assertForbidden();

    $this->actingAs($outsider)
        ->postJson('/api/v1/notification-templates', [
            'channel' => 'sms',
            'name' => 'Nope',
            'body' => 'nope',
        ])
        ->assertForbidden();
});

test('inline preview renders the body with sample data and never sends', function () {
    $admin = nt_user();

    $this->actingAs($admin)
        ->postJson('/api/v1/notification-templates/preview', [
            'channel' => 'email',
            'subject' => 'Invoice {{invoice_number}}',
            'body' => 'Hello {{patient_name}}, amount due {{amount}} at {{appointment_time}}.',
            'data' => ['invoice_number' => 'INV-999'],
        ])
        ->assertOk()
        ->assertJsonPath('data.subject', 'Invoice INV-999')
        ->assertJsonPath('data.body', 'Hello Ahmed Khan, amount due 1,200 PKR at 10:30 AM.');

    expect(NotificationTemplate::count())->toBe(0);
});

test('stored template preview renders subject and body with local sample data', function () {
    $admin = nt_user();
    $template = nt_template(['channel' => 'email', 'name' => 'Receipt', 'subject' => 'Hi {{patient_name}}']);

    $this->actingAs($admin)
        ->postJson("/api/v1/notification-templates/{$template->id}/preview")
        ->assertOk()
        ->assertJsonPath('data.subject', 'Hi Ahmed Khan')
        ->assertJsonPath('data.body', $template->renderBody(NotificationTemplate::PREVIEW_DATA));

    expect(NotificationLog::count())->toBe(0);
});

test('an approved template sends a rendered message recorded with the template id', function () {
    $admin = nt_user();
    $user = User::factory()->create(['phone' => '+1 (555) 210-0041']);
    $template = nt_approved_template([
        'channel' => 'sms',
        'name' => 'Reminder',
        'body' => 'Hi {{patient_name}}, appointment at {{appointment_time}}.',
    ], $admin);

    $deliveries = app(NotificationService::class)->sendFromTemplate($user, $template, [
        'patient_name' => 'Ahmed',
        'appointment_time' => '11:00 AM',
    ]);

    expect($deliveries)->toBe(['sms' => 'logged', 'whatsapp' => 'logged']);

    $this->assertDatabaseHas('notification_logs', [
        'user_id' => $user->id,
        'channel' => 'sms',
        'template_id' => $template->id,
        'body' => 'Hi Ahmed, appointment at 11:00 AM.',
        'status' => 'logged',
    ]);
});

test('an email template send renders the subject from placeholders', function () {
    $admin = nt_user();
    $user = User::factory()->create(['phone' => '+1 (555) 210-0041']);
    $template = nt_approved_template([
        'channel' => 'email',
        'name' => 'Receipt',
        'subject' => 'Receipt {{invoice_number}}',
        'body' => 'Thanks {{patient_name}}.',
    ], $admin);

    app(NotificationService::class)->sendFromTemplate($user, $template, [
        'invoice_number' => 'INV-42',
        'patient_name' => 'Sara',
    ]);

    $this->assertDatabaseHas('notification_logs', [
        'channel' => 'email',
        'template_id' => $template->id,
        'title' => 'Receipt INV-42',
    ]);
});

test('an unapproved template can never be sent', function () {
    $user = User::factory()->create(['phone' => '+1 (555) 210-0041']);
    $template = nt_template(['channel' => 'sms', 'name' => 'Pending']);

    expect(fn () => app(NotificationService::class)->sendFromTemplate($user, $template, []))
        ->toThrow(ValidationException::class);

    expect(NotificationLog::count())->toBe(0);
});

test('partial placeholder data leaves unknown tokens intact instead of dropping them', function () {
    $template = nt_template(['channel' => 'sms', 'name' => 'Reminder', 'body' => 'Hello {{patient_name}}, see {{doctor_name}}.']);

    expect($template->renderBody(['patient_name' => 'Ali']))->toBe('Hello Ali, see {{doctor_name}}.');
});
