<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

/**
 * Payment.method is a constant list (not free text). The gateway integrations
 * are not wired yet — this guards the allowed set only.
 */
beforeEach(function () {
    /** @var User $this->user */
    $this->user = User::factory()->create(['role' => 'Admin']);

    $this->actingAs($this->user)
        ->postJson('/api/v1/invoices', [
            'patient_id' => null,
            'patient_name' => 'Enum Test Patient',
            'subtotal' => 100.00,
            'tax' => 13.00,
        ])
        ->assertCreated();

    /** @var Invoice $this->invoice */
    $this->invoice = Invoice::firstOrFail();
});

test('the payment method list is exactly the agreed set', function () {
    expect(Payment::METHODS)->toBe([
        'cash',
        'bank_transfer',
        'card',
        'jazzcash',
        'easypaisa',
    ]);
});

test('each accepted payment method is validated and recorded', function (string $method) {
    $this->actingAs($this->user)
        ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => 50.00,
            'method' => $method,
        ])
        ->assertOk();

    expect(Payment::firstOrFail()->method)->toBe($method);
})->with(Payment::METHODS);

test('an invalid payment method is rejected', function (string $method) {
    $this->actingAs($this->user)
        ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => 50.00,
            'method' => $method,
        ])
        ->assertUnprocessable();

    expect(Payment::count())->toBe(0);
})->with(['paypal', 'bitcoin', 'credit_card', 'UPI', 'unknown']);

test('omitting the method defaults to cash for backwards compatibility', function () {
    $this->actingAs($this->user)
        ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", ['amount' => 50.00])
        ->assertOk();

    expect(Payment::firstOrFail()->method)->toBe(Payment::METHOD_CASH);
});

test('isValidMethod follows the allowed set', function () {
    expect(Payment::isValidMethod('cash'))->toBeTrue()
        ->and(Payment::isValidMethod('jazzcash'))->toBeTrue()
        ->and(Payment::isValidMethod('easypaisa'))->toBeTrue()
        ->and(Payment::isValidMethod('paypal'))->toBeFalse()
        ->and(Payment::isValidMethod(''))->toBeFalse();
});
