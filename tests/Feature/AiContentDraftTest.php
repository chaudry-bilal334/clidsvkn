<?php

use App\Models\AiContentDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function content_author(): User
{
    return User::factory()->create(['role' => 'Receptionist']);
}

function content_reviewer(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

function content_forbidden_user(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function content_payload(array $overrides = []): array
{
    return [
        'title' => 'Winter clinic opening hours announced',
        'content_type' => 'announcement',
        'content' => 'The clinic will open one hour later on Fridays starting next month so staff can attend continuing training.',
        ...$overrides,
    ];
}

test('an authorised staff member can draft non-clinical content', function () {
    $user = content_author();

    $this->actingAs($user)
        ->postJson('/api/v1/ai-content/drafts', content_payload())
        ->assertCreated()
        ->assertJsonPath('data.title', 'Winter clinic opening hours announced')
        ->assertJsonPath('data.status', 'draft');

    $draft = AiContentDraft::firstOrFail();
    expect($draft->created_by)->toBe($user->id);
    expect($draft->status)->toBe('draft');
});

test('the draft guardrail rejects clinical material and clinical content types', function () {
    $user = content_author();

    $this->actingAs($user)
        ->postJson('/api/v1/ai-content/drafts', content_payload([
            'title' => 'Dosage guidance for the new insulin pen',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');

    $this->actingAs($user)
        ->postJson('/api/v1/ai-content/drafts', content_payload([
            'content_type' => 'clinical_guidance',
        ]))
        ->assertUnprocessable();

    expect(AiContentDraft::count())->toBe(0);
});

test('a draft follows the full workflow but approval never auto-publishes', function () {
    $author = content_author();
    $reviewer = content_reviewer();

    $this->actingAs($author)
        ->postJson('/api/v1/ai-content/drafts', content_payload())
        ->assertCreated();

    $draft = AiContentDraft::firstOrFail();

    $this->actingAs($author)
        ->postJson("/api/v1/ai-content/drafts/{$draft->id}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_review');

    $draft->refresh();
    expect($draft->submitted_by)->toBe($author->id);
    expect($draft->submitted_at)->not->toBeNull();

    $this->actingAs($reviewer)
        ->postJson("/api/v1/ai-content/drafts/{$draft->id}/review", ['decision' => 'approve'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $draft->refresh();
    expect($draft->reviewed_by)->toBe($reviewer->id);
    expect($draft->reviewed_at)->not->toBeNull();
    expect($draft->status)->toBe('approved');
    expect($draft->published_at)->toBeNull();

    $this->actingAs($reviewer)
        ->postJson("/api/v1/ai-content/drafts/{$draft->id}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    $draft->refresh();
    expect($draft->published_at)->not->toBeNull();
});

test('a rejected draft records the reason and can be deleted', function () {
    $author = content_author();
    $reviewer = content_reviewer();

    $this->actingAs($author)
        ->postJson('/api/v1/ai-content/drafts', content_payload())
        ->assertCreated();

    $draft = AiContentDraft::firstOrFail();

    $this->actingAs($author)->postJson("/api/v1/ai-content/drafts/{$draft->id}/submit")->assertOk();

    $this->actingAs($reviewer)
        ->postJson("/api/v1/ai-content/drafts/{$draft->id}/review", [
            'decision' => 'reject',
            'rejection_reason' => 'Please tone down the marketing language.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    $draft->refresh();
    expect($draft->rejection_reason)->toBe('Please tone down the marketing language.');

    $this->actingAs($author)
        ->deleteJson("/api/v1/ai-content/drafts/{$draft->id}")
        ->assertOk();

    expect(AiContentDraft::find($draft->id))->toBeNull();
});

test('a reviewer cannot approve their own submission', function () {
    $author = content_author();

    $this->actingAs($author)
        ->postJson('/api/v1/ai-content/drafts', content_payload())
        ->assertCreated();

    $draft = AiContentDraft::firstOrFail();

    $this->actingAs($author)->postJson("/api/v1/ai-content/drafts/{$draft->id}/submit")->assertOk();

    $this->actingAs($author)
        ->postJson("/api/v1/ai-content/drafts/{$draft->id}/review", ['decision' => 'approve'])
        ->assertUnprocessable();
});

test('state transitions are enforced', function () {
    $user = content_author();

    $this->actingAs($user)
        ->postJson('/api/v1/ai-content/drafts', content_payload())
        ->assertCreated();

    $draft = AiContentDraft::firstOrFail();

    // Cannot be published before approval.
    $this->actingAs(content_reviewer())
        ->postJson("/api/v1/ai-content/drafts/{$draft->id}/publish")
        ->assertUnprocessable();

    // Revision only allowed while still a draft.
    $this->actingAs($user)
        ->putJson("/api/v1/ai-content/drafts/{$draft->id}", ['title' => 'Updated announcement'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Updated announcement');

    $this->actingAs($user)->postJson("/api/v1/ai-content/drafts/{$draft->id}/submit")->assertOk();

    $this->actingAs($user)
        ->putJson("/api/v1/ai-content/drafts/{$draft->id}", ['title' => 'Too late'])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->deleteJson("/api/v1/ai-content/drafts/{$draft->id}")
        ->assertUnprocessable();
});

test('drafts can be listed and filtered by status', function () {
    $user = content_author();

    AiContentDraft::factory()->pendingReview()->create(['title' => 'Pending one']);
    AiContentDraft::factory()->published()->create(['title' => 'Published one']);

    $this->actingAs($user)
        ->getJson('/api/v1/ai-content/drafts')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($user)
        ->getJson('/api/v1/ai-content/drafts?status=pending_review')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Pending one');
});

test('AI content endpoints are gated by role', function () {
    $user = content_forbidden_user();

    $this->actingAs($user)->getJson('/api/v1/ai-content/drafts')->assertForbidden();
    $this->actingAs($user)->postJson('/api/v1/ai-content/drafts', content_payload())->assertForbidden();
});
