<?php

use App\Models\Invoice;
use App\Models\JazzCashTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\JazzCashGatewayDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

pest()->use(RefreshDatabase::class);

const JAZZCASH_SALT = '0F5DD14AE2';

/**
 * Gateway config with throwaway credentials. Nothing here is real — the same
 * shape as config/messaging.php, overridden at runtime as the messaging tests
 * do for the drivers.
 */
function jazzCashConfig(array $overrides = []): array
{
    return [
        'payments.drivers.jazzcash.driver' => JazzCashGatewayDriver::class,
        'payments.drivers.jazzcash.enabled' => true,
        'payments.drivers.jazzcash.env' => 'sandbox',
        'payments.drivers.jazzcash.merchant_id' => 'MER123TEST',
        'payments.drivers.jazzcash.password' => 'test-password',
        'payments.drivers.jazzcash.integrity_salt' => JAZZCASH_SALT,
        'payments.drivers.jazzcash.sandbox_url' => 'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform',
        'payments.drivers.jazzcash.production_url' => 'https://payments.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform',
        'payments.drivers.jazzcash.return_url' => 'http://localhost/api/v1/jazzcash/callback',
        ...$overrides,
    ];
}

function enableJazzCash(array $overrides = []): void
{
    config(jazzCashConfig($overrides));
}

function jazzCashDriver(): JazzCashGatewayDriver
{
    return new JazzCashGatewayDriver(config('payments.drivers.jazzcash'));
}

/**
 * Create a billable invoice.
 *
 * `total` and `balance_due` are server-derived now, so a partially-paid
 * invoice has to be produced by recording a real payment rather than by
 * posting a forged balance.
 */
function makeBillableInvoice(BaseTestCase $test, float $total = 113.00, ?float $balance = null): Invoice
{
    $test->postJson('/api/v1/invoices', [
        'patient_id' => null,
        'patient_name' => 'JazzCash Test Patient',
        'subtotal' => $total,
        'tax' => 0,
    ])->assertCreated();

    $invoice = Invoice::firstOrFail();

    if ($balance !== null && $balance < $total) {
        $accountant = User::factory()->create(['role' => 'Accountant']);

        $test->actingAs($accountant)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
                'amount' => round($total - $balance, 2),
                'method' => 'cash',
            ])
            ->assertOk();
    }

    return $invoice->refresh();
}

function initiateJazzCash(BaseTestCase $test, Invoice $invoice, float $amount): array
{
    $response = $test->postJson("/api/v1/invoices/{$invoice->id}/jazzcash/initiate", ['amount' => $amount]);

    return [
        'response' => $response,
        'data' => $response->json('data'),
        'transaction' => JazzCashTransaction::firstOrFail(),
    ];
}

/**
 * Build a callback payload the way JazzCash would POST it to the Return URL,
 * signed with the configured Integrity Salt.
 */
function signedCallbackPayload(string $txnRef, float $amount, string $code = '000', array $extra = []): array
{
    $transaction = JazzCashTransaction::where('txn_ref_no', $txnRef)->first();
    $billReference = $transaction?->invoice->invoice_number ?? 'INV-00000';

    $fields = array_merge([
        'pp_Version' => '1.1',
        'pp_TxnType' => 'MWALLET',
        'pp_Language' => 'EN',
        'pp_MerchantID' => 'MER123TEST',
        'pp_Password' => 'test-password',
        'pp_TxnRefNo' => $txnRef,
        'pp_Amount' => (string) round($amount * 100),
        'pp_TxnCurrency' => 'PKR',
        'pp_BillReference' => $billReference,
        'pp_ResponseCode' => $code,
        'pp_ResponseMessage' => $code === '000' ? 'Transaction successful' : 'Transaction failed',
        'pp_RetreivalReferenceNo' => 'RRN'.substr(md5((string) mt_rand()), 0, 10),
    ], $extra);

    $fields['pp_SecureHash'] = jazzCashDriver()->computeSignature($fields);

    return $fields;
}

beforeEach(function () {
    /** @var User $this->user */
    $this->user = User::factory()->create(['role' => 'Admin']);

    $this->actingAs($this->user);
});

test('the secure hash matches the official JazzCash documentation example', function () {
    $driver = new JazzCashGatewayDriver(['integrity_salt' => JAZZCASH_SALT]);

    $hash = $driver->computeSignature([
        'pp_MerchantID' => 'MER123',
        'pp_OrderInfo' => 'A48cvE28',
        'pp_Amount' => '2995',
    ]);

    // Worked example from the JazzCash v4.2 sandbox docs (Features > Hashing scheme).
    expect($hash)->toBe('c7689cda7474eb1adcd343fd0c0b676bad0ba66361cc46db589bdb0da4c1c867');
});

test('initiate returns signed merchantform fields and records a pending transaction', function () {
    enableJazzCash();

    $invoice = makeBillableInvoice($this);

    $result = initiateJazzCash($this, $invoice, 113.00);

    $result['response']
        ->assertOk()
        ->assertJsonPath('data.redirect_url', 'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform');

    expect($result['transaction']->status)->toBe(JazzCashTransaction::STATUS_PENDING);
    expect((float) $result['transaction']->amount)->toBe(113.0);
    expect($result['transaction']->invoice_id)->toBe($invoice->id);
    expect($result['data']['transaction']['txn_ref_no'])->toBe($result['transaction']->txn_ref_no);

    $fields = $result['data']['fields'];

    expect($fields['pp_MerchantID'])->toBe('MER123TEST');
    expect($fields['pp_Amount'])->toBe('11300'); // PKR, whole paisa, no decimals
    expect($fields['pp_TxnCurrency'])->toBe('PKR');
    expect($fields['pp_TxnType'])->toBe('MWALLET');
    expect($fields['pp_ReturnURL'])->toBe('http://localhost/api/v1/jazzcash/callback');
    expect($fields['pp_BillReference'])->toBe($invoice->invoice_number);
    expect($fields['pp_SecureHash'])->not->toBeEmpty();
    expect(jazzCashDriver()->verifySignature($fields))->toBeTrue();
});

test('initiate rejects an amount above the balance due', function () {
    enableJazzCash();

    $invoice = makeBillableInvoice($this, total: 113.00, balance: 60.00);

    // The one legitimate payment that produced the 60.00 balance.
    $cashPayment = Payment::query()->sole();

    $this->postJson("/api/v1/invoices/{$invoice->id}/jazzcash/initiate", ['amount' => 61.00])
        ->assertStatus(422);

    expect(JazzCashTransaction::count())->toBe(0)
        // The rejected attempt must not have left a second payment behind.
        ->and(Payment::count())->toBe(1)
        ->and((float) $invoice->balance_due)->toBe(60.0)
        ->and($cashPayment->amount)->toEqual(53.0);
});

test('initiate fails gracefully when the gateway is unconfigured', function () {
    // Defaults from config: enabled=false, empty credentials — nothing sent.
    $invoice = makeBillableInvoice($this);

    $this->postJson("/api/v1/invoices/{$invoice->id}/jazzcash/initiate", ['amount' => 50.00])
        ->assertStatus(503)
        ->assertJsonPath('success', false);

    expect(JazzCashTransaction::count())->toBe(0);
    expect(Payment::count())->toBe(0);
});

test('a verified success callback records the payment and settles the invoice', function () {
    enableJazzCash();

    $invoice = makeBillableInvoice($this);

    $result = initiateJazzCash($this, $invoice, 113.00);
    $txnRef = $result['transaction']->txn_ref_no;

    $this->post('/api/v1/jazzcash/callback', signedCallbackPayload($txnRef, 113.00))
        ->assertOk()
        ->assertSee('?jazzcash=success', false);

    $this->assertDatabaseHas('payments', [
        'invoice_id' => $invoice->id,
        'amount' => 113.00,
        'method' => 'jazzcash',
        'reference' => $txnRef,
    ]);

    $invoice->refresh();
    expect((float) $invoice->balance_due)->toBe(0.0);
    expect($invoice->status)->toBe('paid');

    $this->assertDatabaseHas('jazzcash_transactions', [
        'txn_ref_no' => $txnRef,
        'status' => 'completed',
        'response_code' => '000',
    ]);
});

test('a verified partial payment keeps the invoice open', function () {
    enableJazzCash();

    $invoice = makeBillableInvoice($this);

    $result = initiateJazzCash($this, $invoice, 50.00);

    $this->post('/api/v1/jazzcash/callback', signedCallbackPayload($result['transaction']->txn_ref_no, 50.00))
        ->assertOk();

    $invoice->refresh();
    expect((float) $invoice->balance_due)->toBe(63.0);
    expect((float) $invoice->amount_paid)->toBe(50.0);
    expect($invoice->status)->toBe('generated');
});

test('duplicate callbacks are idempotent and never double-record', function () {
    enableJazzCash();

    $invoice = makeBillableInvoice($this);

    $result = initiateJazzCash($this, $invoice, 113.00);
    $txnRef = $result['transaction']->txn_ref_no;

    $payload = signedCallbackPayload($txnRef, 113.00);

    $this->post('/api/v1/jazzcash/callback', $payload)->assertOk();
    $this->post('/api/v1/jazzcash/callback', $payload)->assertOk();

    expect(Payment::where('reference', $txnRef)->count())->toBe(1);

    $invoice->refresh();
    expect((float) $invoice->balance_due)->toBe(0.0);
    expect($invoice->status)->toBe('paid');
});

test('a tampered callback signature is rejected and changes nothing', function () {
    enableJazzCash();

    $invoice = makeBillableInvoice($this);

    $result = initiateJazzCash($this, $invoice, 113.00);
    $txnRef = $result['transaction']->txn_ref_no;

    $payload = signedCallbackPayload($txnRef, 113.00);
    $payload['pp_Amount'] = '99999'; // altered after signing

    $this->post('/api/v1/jazzcash/callback', $payload)
        ->assertStatus(400)
        ->assertJsonPath('message', 'Invalid secure hash.');

    expect(Payment::count())->toBe(0);

    $this->assertDatabaseHas('jazzcash_transactions', [
        'txn_ref_no' => $txnRef,
        'status' => 'pending',
    ]);

    $invoice->refresh();
    expect((float) $invoice->balance_due)->toBe(113.0);
    expect($invoice->status)->toBe('generated');
});

test('a gateway failure callback leaves the invoice untouched and marks the attempt failed', function () {
    enableJazzCash();

    $invoice = makeBillableInvoice($this);

    $result = initiateJazzCash($this, $invoice, 113.00);
    $txnRef = $result['transaction']->txn_ref_no;

    $this->post('/api/v1/jazzcash/callback', signedCallbackPayload($txnRef, 113.00, code: '404'))
        ->assertOk()
        ->assertSee('?jazzcash=failed', false);

    expect(Payment::count())->toBe(0);

    $this->assertDatabaseHas('jazzcash_transactions', [
        'txn_ref_no' => $txnRef,
        'status' => 'failed',
        'response_code' => '404',
    ]);

    $invoice->refresh();
    expect((float) $invoice->balance_due)->toBe(113.0);
    expect($invoice->status)->toBe('generated');
});

test('a callback for an unknown transaction reference is ignored', function () {
    enableJazzCash();

    makeBillableInvoice($this);

    $this->post('/api/v1/jazzcash/callback', signedCallbackPayload('JAZZ-NOPE-11111111', 50.00))
        ->assertStatus(400)
        ->assertJsonPath('message', 'Unknown transaction reference.');

    expect(Payment::count())->toBe(0);
    expect(JazzCashTransaction::count())->toBe(0);
});

test('callbacks received while the gateway is unconfigured fail closed', function () {
    // enabled=false, no credentials — drive the default config.
    $invoice = makeBillableInvoice($this);

    $this->post('/api/v1/jazzcash/callback', [
        'pp_MerchantID' => 'MER123TEST',
        'pp_TxnRefNo' => 'JAZZ-X-12345678',
        'pp_ResponseCode' => '000',
        'pp_SecureHash' => 'anything',
    ])->assertOk()->assertSee('?jazzcash=failed', false);

    expect(Payment::count())->toBe(0);
});
