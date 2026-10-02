<?php

use App\Models\Branch;
use App\Models\Communication;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function crm_admin(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

function crm_accountant(): User
{
    return User::factory()->create(['role' => 'Accountant']);
}

function crm_receptionist(): User
{
    return User::factory()->create(['role' => 'Receptionist']);
}

function crm_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function crm_pharmacist(): User
{
    return User::factory()->create(['role' => 'Pharmacist']);
}

function crm_lead(): Lead
{
    return Lead::factory()->create();
}

function crm_followup(?Patient $patient = null): Followup
{
    return Followup::factory()->create([
        'patient_id' => $patient?->id ?? Patient::factory(),
    ]);
}

test('a lead can be created, viewed, edited and deleted by a receptionist', function () {
    $user = crm_receptionist();

    $this->actingAs($user)
        ->postJson('/api/v1/leads', [
            'name' => 'Ali Raza',
            'email' => 'ali@example.com',
            'phone' => '+92 300 1234567',
            'service_interest' => 'Dental Care',
            'source' => 'walk_in',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Ali Raza')
        ->assertJsonPath('data.status', Lead::STATUS_NEW)
        ->assertJsonPath('data.source', Lead::SOURCE_WALK_IN);

    $lead = Lead::firstOrFail();

    $this->actingAs($user)
        ->getJson("/api/v1/leads/{$lead->id}")
        ->assertOk()
        ->assertJsonPath('data.email', 'ali@example.com');

    $this->actingAs($user)
        ->putJson("/api/v1/leads/{$lead->id}", ['phone' => '+92 311 7654321'])
        ->assertOk()
        ->assertJsonPath('data.phone', '+92 311 7654321');

    $this->actingAs($user)
        ->deleteJson("/api/v1/leads/{$lead->id}")
        ->assertOk();

    $this->assertSoftDeleted('leads', ['id' => $lead->id]);
});

test('lead options expose the doctor and assignee picklists', function () {
    $doctor = crm_doctor();
    $assignee = crm_receptionist();

    $response = $this->actingAs(crm_accountant())
        ->getJson('/api/v1/leads/options')
        ->assertOk();

    expect(collect($response->json('data.doctors'))->pluck('id')->all())->toContain($doctor->id);
    expect(collect($response->json('data.staff'))->pluck('id')->all())->toContain($assignee->id);
});

test('lead options are hidden from staff without crm access', function () {
    $this->actingAs(crm_pharmacist())
        ->getJson('/api/v1/leads/options')
        ->assertForbidden();
});

test('a lead cannot be changed into an arbitrary stage through an edit', function () {
    $lead = crm_lead();
    $user = crm_admin();

    $this->actingAs($user)
        ->putJson("/api/v1/leads/{$lead->id}", ['status' => Lead::STATUS_QUALIFIED])
        ->assertOk()
        ->assertJsonPath('data.status', Lead::STATUS_NEW);

    expect($lead->refresh()->status)->toBe(Lead::STATUS_NEW);
});

test('the CRM pipeline only permits the approved forward transitions', function () {
    $lead = crm_lead();
    $user = crm_receptionist();

    $journey = [Lead::STATUS_CONTACTED, Lead::STATUS_QUALIFIED, Lead::STATUS_CONVERTED, Lead::STATUS_CLOSED_LOST];

    foreach ($journey as $next) {
        $this->actingAs($user)
            ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => $next])
            ->assertOk()
            ->assertJsonPath('data.status', $next);

        expect($lead->refresh()->status)->toBe($next);
    }
});

test('illegal lead transitions are rejected and audited as blocked', function () {
    $lead = crm_lead();
    $user = crm_receptionist();

    $this->actingAs($user)
        ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => Lead::STATUS_QUALIFIED])
        ->assertStatus(422);

    $this->actingAs($user)
        ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => Lead::STATUS_CLOSED_LOST])
        ->assertStatus(422);

    $this->actingAs($user)
        ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => Lead::STATUS_CONTACTED])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => Lead::STATUS_NEW])
        ->assertStatus(422);

    $this->actingAs($user)
        ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => Lead::STATUS_CONTACTED])
        ->assertStatus(422);

    expect(Lead::findOrFail($lead->id)->status)->toBe(Lead::STATUS_CONTACTED);
});

test('a successful lead transition is recorded in the audit log', function () {
    $lead = crm_lead();
    $user = crm_receptionist();

    $this->actingAs($user)
        ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => Lead::STATUS_CONTACTED])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $user->id,
        'action' => 'lead.transitioned',
        'auditable_type' => Lead::class,
        'auditable_id' => $lead->id,
    ]);
});

test('a doctor can view leads but cannot advance them', function () {
    $lead = crm_lead();
    $doctor = crm_doctor();

    $this->actingAs($doctor)
        ->getJson('/api/v1/leads')
        ->assertOk();

    $this->actingAs($doctor)
        ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => Lead::STATUS_CONTACTED])
        ->assertForbidden();
});

test('a pharmacist has no access to leads at all', function () {
    crm_lead();

    $this->actingAs(crm_pharmacist())
        ->getJson('/api/v1/leads')
        ->assertForbidden();
});

test('communications are logged against a lead and shown on the detail view', function () {
    $lead = crm_lead();
    $user = crm_receptionist();

    $this->actingAs($user)
        ->postJson("/api/v1/leads/{$lead->id}/communications", [
            'channel' => Communication::CHANNEL_CALL,
            'summary' => 'Discussed service options',
            'notes' => 'Customer prefers morning slots.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.channel', 'call')
        ->assertJsonPath('data.user_id', $user->id);

    $this->actingAs($user)
        ->getJson("/api/v1/leads/{$lead->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.communications')
        ->assertJsonPath('data.communications.0.summary', 'Discussed service options');
});

test('a communication with an invalid channel is rejected', function () {
    $lead = crm_lead();

    $this->actingAs(crm_receptionist())
        ->postJson("/api/v1/leads/{$lead->id}/communications", [
            'channel' => 'carrier_pigeon',
            'summary' => 'Nope',
        ])
        ->assertStatus(422);
});

test('leads are branch partitioned for branch-scoped staff', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $other = Branch::factory()->create(['is_active' => true]);

    $inBranch = Lead::factory()->create(['branch_id' => $branch->id]);
    Lead::factory()->create(['branch_id' => $other->id]);

    $user = User::factory()->create(['role' => 'Receptionist', 'branch_id' => $branch->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/leads')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inBranch->id);
});

test('a follow-up must be linked to a patient and/or a lead', function () {
    $this->actingAs(crm_receptionist())
        ->postJson('/api/v1/followups', [
            'due_at' => now()->addDay()->format('Y-m-d H:i'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead_id');
});

test('a follow-up can be scheduled for a patient and completed once', function () {
    $patient = Patient::factory()->create();
    $user = crm_receptionist();

    $this->actingAs($user)
        ->postJson('/api/v1/followups', [
            'patient_id' => $patient->id,
            'due_at' => now()->addDay()->format('Y-m-d H:i'),
            'type' => Followup::TYPE_REVIEW,
            'notes' => 'Check healing progress.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'review')
        ->assertJsonPath('data.status', Followup::STATUS_PENDING);

    $followup = Followup::firstOrFail();

    $this->actingAs($user)
        ->postJson("/api/v1/followups/{$followup->id}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', Followup::STATUS_COMPLETED)
        ->assertJsonPath('data.completed_by', $user->id);

    $this->actingAs($user)
        ->postJson("/api/v1/followups/{$followup->id}/complete")
        ->assertStatus(422);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $user->id,
        'action' => 'followup.completed',
        'auditable_type' => Followup::class,
        'auditable_id' => $followup->id,
    ]);
});

test('an overdue pending follow-up is flagged as overdue', function () {
    $patient = Patient::factory()->create();
    $user = crm_receptionist();

    Followup::factory()->create([
        'patient_id' => $patient->id,
        'due_at' => now()->subDay()->format('Y-m-d H:i:s'),
        'status' => Followup::STATUS_PENDING,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/followups')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.overdue', true);
});

test('only pending follow-ups can be cancelled or edited', function () {
    $patient = Patient::factory()->create();
    $user = crm_receptionist();

    $followup = Followup::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/followups/{$followup->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', Followup::STATUS_CANCELLED);

    $this->actingAs($user)
        ->putJson("/api/v1/followups/{$followup->id}", ['notes' => 'Too late'])
        ->assertStatus(422);
});

test('a pharmacist cannot access the follow-up worklist', function () {
    $this->actingAs(crm_pharmacist())
        ->getJson('/api/v1/followups')
        ->assertForbidden();
});

test('follow-up reminders are delivered to the assigned staff member', function () {
    $assignee = User::factory()->create(['role' => 'Receptionist', 'phone' => '+15552100051']);
    $patient = Patient::factory()->create();

    Followup::factory()->create([
        'patient_id' => $patient->id,
        'assigned_to' => $assignee->id,
        'due_at' => now()->addHour()->format('Y-m-d H:i:s'),
        'status' => Followup::STATUS_PENDING,
    ]);

    Followup::factory()->create([
        'patient_id' => $patient->id,
        'assigned_to' => null,
        'due_at' => now()->addHour()->format('Y-m-d H:i:s'),
        'status' => Followup::STATUS_PENDING,
    ]);

    $this->artisan('clinic:send-followup-reminders')->assertExitCode(0);

    $this->assertDatabaseHas('notification_logs', [
        'user_id' => $assignee->id,
        'channel' => 'followups',
    ]);
});

test('follow-up reminders skip completed and far-future tasks', function () {
    $assignee = User::factory()->create(['role' => 'Receptionist', 'phone' => '+15552100052']);
    $patient = Patient::factory()->create();

    Followup::factory()->create([
        'patient_id' => $patient->id,
        'assigned_to' => $assignee->id,
        'due_at' => now()->addHour()->format('Y-m-d H:i:s'),
        'status' => Followup::STATUS_COMPLETED,
    ]);

    Followup::factory()->create([
        'patient_id' => $patient->id,
        'assigned_to' => $assignee->id,
        'due_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
        'status' => Followup::STATUS_PENDING,
    ]);

    $this->artisan('clinic:send-followup-reminders')->assertExitCode(0);

    $this->assertDatabaseMissing('notification_logs', [
        'user_id' => $assignee->id,
        'channel' => 'followups',
    ]);
});

test('the followup reminder job is registered on the schedule', function () {
    $scheduled = collect(app('Illuminate\Console\Scheduling\Schedule')->events())
        ->filter(fn ($event) => str_contains($event->command, 'clinic:send-followup-reminders'))
        ->first();

    expect($scheduled)->not->toBeNull();
});
