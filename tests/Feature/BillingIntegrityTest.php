<?php

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

pest()->use(RefreshDatabase::class);

function bill_user(): User
{
    return User::factory()->create(['role' => 'Accountant']);
}

function bill_invoice(float $total = 100.00): Invoice
{
    return Invoice::factory()->create([
        'subtotal' => $total,
        'tax' => 0,
        'total' => $total,
        'amount_paid' => 0,
        'balance_due' => $total,
        'status' => Invoice::STATUS_GENERATED,
    ]);
}

test('a client cannot forge invoice money fields on create', function () {
    $user = bill_user();

    // `status`, `total`, `amount_paid` and `balance_due` are all rejected or
    // ignored: a client that could post `balance_due` would be posting
    // `amount_paid` too, and could mark a charge collected with no payment row.
    $this->actingAs($user)
        ->postJson('/api/v1/invoices', [
            'patient_name' => 'Forgery Attempt',
            'subtotal' => 100.00,
            'tax' => 0,
            'status' => 'paid',
            'amount_paid' => 100.00,
            'balance_due' => 0.00,
            'total' => 99999.00,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'generated')
        ->assertJsonPath('data.total', 100)
        ->assertJsonPath('data.amount_paid', 0)
        ->assertJsonPath('data.balance_due', 100);

    expect(Invoice::firstOrFail()->status)->toBe(Invoice::STATUS_GENERATED)
        ->and(Payment::count())->toBe(0);
});

test('recording a payment writes the payment row, the balance and the audit entry together', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 40.00,
            'method' => Payment::METHOD_CASH,
        ])
        ->assertOk()
        ->assertJsonPath('data.amount_paid', 40)
        ->assertJsonPath('data.balance_due', 60)
        ->assertJsonPath('data.status', 'generated');

    expect(Payment::count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'payment.recorded')->count())->toBe(1)
        // The cashier is recorded, so the money trail has a person on it.
        ->and(Payment::firstOrFail()->received_by)->toBe($user->id);
});

test('an overpayment is rejected and leaves no payment or audit trail behind', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => 100.01])
        ->assertUnprocessable()
        ->assertJsonPath('errors.amount.0', 'The payment amount exceeds the balance due on this invoice.');

    expect(Payment::count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'payment.recorded')->count())->toBe(0)
        ->and($invoice->refresh()->balance_due)->toBe('100.00');
});

test('a settled invoice refuses further payments', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => 100.00])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => 10.00])
        ->assertUnprocessable()
        ->assertJsonPath('errors.invoice_id.0', 'This invoice has no outstanding balance.');

    expect(Payment::count())->toBe(1);
});

test('a replayed gateway transaction reference cannot settle the charge twice', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 30.00,
            'method' => Payment::METHOD_JAZZCASH,
            'reference' => 'JC-TXN-0001',
        ])
        ->assertOk();

    // The same webhook firing again must not create a second payment.
    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 30.00,
            'method' => Payment::METHOD_JAZZCASH,
            'reference' => 'JC-TXN-0001',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.reference.0', 'This transaction reference has already been recorded against a payment.');

    expect(Payment::count())->toBe(1)
        ->and($invoice->refresh()->amount_paid)->toBe('30.00');
});

test('concurrent payments cannot both pass the over-payment guard', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);

    $service = app(BillingService::class);

    $service->recordPayment($invoice, 60.00, Payment::METHOD_CASH, $user);

    // The second cashier read a stale balance in the UI, so the guard has to be
    // re-evaluated against the locked row rather than the caller's copy.
    expect(fn () => $service->recordPayment($invoice, 60.00, Payment::METHOD_CASH, $user))
        ->toThrow(ValidationException::class);

    expect(Payment::count())->toBe(1)
        ->and($invoice->refresh()->amount_paid)->toBe('60.00')
        ->and($invoice->balance_due)->toBe('40.00');
});

test('reversing a payment writes a linked negative entry instead of deleting it', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);
    $service = app(BillingService::class);

    $payment = $service->recordPayment($invoice, 80.00, Payment::METHOD_CARD, $user, 'STRIPE-1');

    $reversal = $service->reversePayment($payment, $user, 'Card charged in error');

    expect(Payment::count())->toBe(2)
        ->and((float) $reversal->amount)->toBe(-80.0)
        ->and($reversal->reversal_of)->toBe($payment->id)
        ->and($payment->refresh()->status)->toBe(Payment::STATUS_REVERSED)
        ->and($invoice->refresh()->amount_paid)->toBe('0.00')
        ->and($invoice->balance_due)->toBe('100.00')
        ->and(AuditLog::query()->where('action', 'payment.reversed')->count())->toBe(1);
});

test('a payment cannot be reversed twice', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);
    $service = app(BillingService::class);

    $payment = $service->recordPayment($invoice, 50.00, Payment::METHOD_CASH, $user);
    $service->reversePayment($payment, $user, 'Mistake');

    expect(fn () => $service->reversePayment($payment->refresh(), $user, 'Again'))
        ->toThrow(ValidationException::class);
});

test('finalizing an invoice freezes it and is recorded against the actor', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);

    // Finalization is a re-auth guarded transition (PRD §13).
    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/finalize", ['confirmation' => true])
        ->assertForbidden()
        ->assertJsonPath('reauth_required', true);

    $this->actingAs($user)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/invoices/{$invoice->id}/finalize", ['confirmation' => true])
        ->assertOk()
        ->assertJsonPath('data.is_finalized', true)
        ->assertJsonPath('data.status', 'finalized');

    expect($invoice->refresh()->finalized_by)->toBe($user->id)
        ->and($invoice->finalized_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'invoice.finalized')->count())->toBe(1);

    // Finalizing twice is not a no-op success; the second call is a conflict.
    $this->actingAs($user)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/invoices/{$invoice->id}/finalize", ['confirmation' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.invoice_id.0', 'This invoice is already finalized.');
});

test('a finalized invoice record cannot be deleted out from under the audit trail', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);

    app(BillingService::class)->recordPayment($invoice, 25.00, Payment::METHOD_CASH, $user);

    // The payment FK is RESTRICT, so a cascade cannot erase settled money.
    expect(fn () => $invoice->delete())
        ->toThrow(QueryException::class);

    expect(Invoice::whereKey($invoice->id)->exists())->toBeTrue()
        ->and(Payment::count())->toBe(1);
});

test('a finalized invoice refuses further payments', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);

    $this->actingAs($user)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/invoices/{$invoice->id}/finalize", ['confirmation' => true])
        ->assertOk();

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 10.00,
            'method' => Payment::METHOD_CASH,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.invoice_id.0', 'A finalized invoice cannot accept payments. Issue a refund or reversal instead.');

    expect(Payment::count())->toBe(0);
});

test('a finalized invoice line item cannot be edited or deleted', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);

    $item = $invoice->items()->create([
        'description' => 'Paracetamol 500mg',
        'quantity' => 10,
        'unit_price' => 5.00,
        'line_total' => 50.00,
    ]);

    $this->actingAs($user)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/invoices/{$invoice->id}/finalize", ['confirmation' => true])
        ->assertOk();

    expect(fn () => $item->update(['quantity' => 20]))
        ->toThrow(ValidationException::class);

    expect(fn () => $item->delete())
        ->toThrow(ValidationException::class);

    expect((float) $item->refresh()->quantity)->toBe(10.0);
});

test('a finalized invoice cannot be deleted', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);

    $this->actingAs($user)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/invoices/{$invoice->id}/finalize", ['confirmation' => true])
        ->assertOk();

    $invoice->refresh();

    expect(fn () => $invoice->delete())
        ->toThrow(ValidationException::class);

    expect(Invoice::whereKey($invoice->id)->exists())->toBeTrue();
});

test('the legitimate payment reversal path still works on a finalized invoice', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);

    $payment = app(BillingService::class)->recordPayment($invoice, 40.00, Payment::METHOD_CASH, $user);

    $this->actingAs($user)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/invoices/{$invoice->id}/finalize", ['confirmation' => true])
        ->assertOk();

    $reversal = app(BillingService::class)->reversePayment($payment, $user, 'Customer returned goods');

    expect((float) $reversal->amount)->toBe(-40.0)
        ->and($reversal->reversal_of)->toBe($payment->id)
        ->and((float) $invoice->refresh()->amount_paid)->toBe(0.0)
        ->and((float) $invoice->balance_due)->toBe(100.0);
});

test('the payment reversal endpoint is reachable behind billing.manage and reauth', function () {
    $user = bill_user();
    $invoice = bill_invoice(100.00);

    $payment = app(BillingService::class)->recordPayment($invoice, 40.00, Payment::METHOD_CASH, $user);

    // Without re-auth the reversal is forbidden.
    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments/{$payment->id}/reverse", [
            'reason' => 'Customer returned goods',
        ])
        ->assertForbidden()
        ->assertJsonPath('reauth_required', true);

    // With re-auth the reversal succeeds.
    $this->actingAs($user)
        ->withSession(['reauth_verified_at' => now()->timestamp])
        ->postJson("/api/v1/invoices/{$invoice->id}/payments/{$payment->id}/reverse", [
            'reason' => 'Customer returned goods',
        ])
        ->assertCreated()
        ->assertJsonPath('data.amount', -40)
        ->assertJsonPath('data.reversal_of', $payment->id)
        ->assertJsonPath('data.invoice.amount_paid', 0)
        ->assertJsonPath('data.invoice.balance_due', 100);
});
