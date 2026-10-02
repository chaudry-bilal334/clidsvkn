<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\JazzCashTransaction;
use App\Models\Payment;
use App\Services\PaymentGatewayService;
use App\Services\Payments\JazzCashGatewayDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class JazzCashPaymentController extends Controller
{
    public function __construct(private readonly PaymentGatewayService $gateways) {}

    /**
     * Start a JazzCash hosted checkout for an invoice. Returns the gateway URL
     * plus the signed hidden form fields the billing UI submits to it. When
     * the gateway is unconfigured this fails gracefully (503, nothing sent).
     */
    public function initiate(Request $request, Invoice $invoice): JsonResponse
    {
        $driver = $this->gateways->driver('jazzcash');

        if ($driver === null || ! $driver->isEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'The JazzCash payment gateway is not configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $amount = round((float) $validated['amount'], 2);

        if ($amount > (float) $invoice->balance_due) {
            return response()->json([
                'success' => false,
                'message' => 'The payment amount exceeds the balance due on this invoice.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $transaction = JazzCashTransaction::create([
            'invoice_id' => $invoice->id,
            'txn_ref_no' => sprintf('JAZZ-%d-%s', $invoice->id, Str::upper(Str::random(8))),
            'amount' => $amount,
            'status' => JazzCashTransaction::STATUS_PENDING,
        ]);

        $redirect = $driver->initiate([
            'txn_ref_no' => $transaction->txn_ref_no,
            'amount' => $amount,
            'bill_reference' => $invoice->invoice_number,
            'description' => 'Payment for invoice '.$invoice->invoice_number,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'redirect_url' => $redirect['redirect_url'],
                'fields' => $redirect['fields'],
                'transaction' => [
                    'id' => $transaction->id,
                    'txn_ref_no' => $transaction->txn_ref_no,
                    'amount' => (float) $transaction->amount,
                ],
            ],
        ]);
    }

    /**
     * Receives the POST that JazzCash sends to the Return URL after a hosted
     * checkout. The HMAC signature is verified before any state changes — a
     * tampered or unverifiable payload is rejected and can never mark a
     * payment complete. Responds with a tiny page that bounces the browser
     * back to the SPA billing screen with the outcome.
     */
    public function callback(Request $request): Response
    {
        $fields = $request->all();

        $driver = $this->gateways->driver('jazzcash');

        if ($driver === null || ! $driver->isEnabled()) {
            Log::warning('[jazzcash] callback received while the gateway is not configured');

            return $this->redirectHtml('failed', '');
        }

        $txnRef = (string) ($fields['pp_TxnRefNo'] ?? '');

        /** @var JazzCashTransaction|null $transaction */
        $transaction = JazzCashTransaction::query()
            ->where('txn_ref_no', $txnRef)
            ->first();

        if ($transaction === null) {
            Log::warning("[jazzcash] callback for unknown transaction reference: {$txnRef}");

            return response()->json([
                'success' => false,
                'message' => 'Unknown transaction reference.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($transaction->status === JazzCashTransaction::STATUS_COMPLETED) {
            // Idempotent: the gateway may re-deliver a callback.
            return $this->redirectHtml('success', $txnRef);
        }

        if (! $driver->verifySignature($fields)) {
            Log::warning("[jazzcash] callback signature mismatch for {$txnRef}");

            return response()->json([
                'success' => false,
                'message' => 'Invalid secure hash.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $code = (string) ($fields['pp_ResponseCode'] ?? '');
        $message = (string) ($fields['pp_ResponseMessage'] ?? '');

        $transaction->response_code = $code !== '' ? $code : null;
        $transaction->response_message = $message !== '' ? $message : null;
        $transaction->retrieval_reference_no = $this->presentString($fields['pp_RetreivalReferenceNo'] ?? null);
        $transaction->callback_payload = $fields;

        if ($code === JazzCashGatewayDriver::CODE_SUCCESS) {
            if (! $this->completePayment($transaction, $fields)) {
                $transaction->status = JazzCashTransaction::STATUS_FAILED;
                $transaction->save();

                return $this->redirectHtml('failed', $txnRef);
            }

            $transaction->status = JazzCashTransaction::STATUS_COMPLETED;
            $transaction->save();

            return $this->redirectHtml('success', $txnRef);
        }

        $transaction->status = JazzCashTransaction::STATUS_FAILED;
        $transaction->save();
        Log::warning("[jazzcash] transaction {$txnRef} not completed: code {$code} ({$message})");

        return $this->redirectHtml('failed', $txnRef);
    }

    /**
     * Record a verified successful payment against the invoice, exactly as the
     * manual payment path does: create the Payment row (method=jazzcash,
     * reference=txn ref) and re-roll the invoice balance/status. Returns false
     * without touching anything when the callback amount disagrees with the
     * initiated amount — an anomaly that must surface instead of corrupting
     * the invoice state.
     *
     * @param  array<string, mixed>  $fields
     */
    private function completePayment(JazzCashTransaction $transaction, array $fields): bool
    {
        $invoice = $transaction->invoice;

        /** @var JazzCashGatewayDriver $driver */
        $driver = $this->gateways->driver('jazzcash');

        $callbackAmount = $driver->amountFromMinorUnits((string) ($fields['pp_Amount'] ?? '0'));

        if (abs($callbackAmount - (float) $transaction->amount) > 0.01) {
            Log::warning("[jazzcash] amount mismatch for {$transaction->txn_ref_no}: initiated ".$transaction->amount.", callback {$callbackAmount}");

            return false;
        }

        if (Payment::where('reference', $transaction->txn_ref_no)->exists()) {
            return true;
        }

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $callbackAmount,
            'method' => Payment::METHOD_JAZZCASH,
            'reference' => $transaction->txn_ref_no,
            'paid_at' => now(),
        ]);

        $invoice->amount_paid = (float) $invoice->amount_paid + $callbackAmount;
        $invoice->balance_due = round((float) $invoice->total - $invoice->amount_paid, 2);
        $invoice->status = $invoice->balance_due > 0 ? $invoice->status : Invoice::STATUS_PAID;
        $invoice->save();

        return true;
    }

    private function presentString(mixed $value): ?string
    {
        $value = (string) ($value ?? '');

        return $value !== '' ? $value : null;
    }

    /**
     * The callback is rendered back into the browser that JazzCash redirected,
     * so the response is a tiny HTML page bouncing it to the SPA with the
     * outcome, not JSON.
     */
    private function redirectHtml(string $status, string $txnRef): Response
    {
        $base = rtrim((string) (config('payments.return_redirect_url') ?? url('/billing')), '/');
        $url = $base.'?jazzcash='.$status;

        if ($txnRef !== '') {
            $url .= '&ref='.rawurlencode($txnRef);
        }

        $heading = $status === 'success' ? 'Payment completed' : 'Payment not completed';
        $escaped = e($url);
        $jsUrl = json_encode($url, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$heading}</title>
<meta http-equiv="refresh" content="0; url={$escaped}">
<script>window.location.replace({$jsUrl});</script>
</head>
<body></body>
</html>
HTML;

        return response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
