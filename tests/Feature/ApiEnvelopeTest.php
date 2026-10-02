<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

pest()->use(RefreshDatabase::class);

/**
 * Every API response — success and error path alike — must carry the same
 * envelope shape `{success, message, data, meta:{request_id}}` (PRD §14),
 * and the `meta.request_id` value must match the `X-Request-Id` response
 * header so a single id correlates a request end-to-end.
 */
function envelope_admin(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

function assertApiEnvelope(TestResponse $response): void
{
    $response->assertHeader('X-Request-Id');

    $body = $response->json();
    $requestId = $response->headers->get('X-Request-Id');

    expect($body)->toHaveKeys(['success', 'message', 'data', 'meta']);
    expect($body['success'])->toBeBool();
    expect($body['message'])->toBeString();
    expect($body['data'] === null || is_array($body['data']))->toBeTrue();
    expect($body['meta'] ?? [])->toBeArray();
    expect($body['meta']['request_id'] ?? null)->toBeString();

    // The envelope meta id must equal the correlation header on the wire.
    expect($body['meta']['request_id'])->toBe($requestId);
}

test('public success endpoint returns the full envelope (health)', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk();
    assertApiEnvelope($response);
});

test('authenticated data endpoint returns the full envelope', function () {
    $response = $this->actingAs(envelope_admin())->getJson('/api/v1/patients');

    $response->assertOk()
        ->assertJsonCount(0, 'data');
    assertApiEnvelope($response);
});

test('validation error returns the envelope with errors mapped', function () {
    $response = $this->actingAs(envelope_admin())->postJson('/api/v1/patients', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('name');
    assertApiEnvelope($response);
});

test('permission error returns the envelope', function () {
    // Accountants hold billing/claims permissions, but not patients.view.
    $response = $this->actingAs(User::factory()->create(['role' => 'Accountant']))
        ->getJson('/api/v1/patients');

    $response->assertForbidden();
    assertApiEnvelope($response);
});

test('missing resource returns the envelope', function () {
    $response = $this->actingAs(envelope_admin())->getJson('/api/v1/patients/999999');

    $response->assertNotFound();
    assertApiEnvelope($response);
});
