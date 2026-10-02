<?php

namespace App\Services\Payments;

use App\Contracts\Payment\PaymentGatewayInterface;

/**
 * JazzCash Payment Gateway — HTTP POST (Page Redirection) integration for
 * Mobile Account (MWALLET) payments, per the official v4.2 merchant docs:
 *
 * - The merchant posts a signed field set to the hosted "merchantform" URL;
 * - the customer authorizes on the JazzCash-hosted page;
 * - JazzCash POSTs the transaction result back to the Return URL with a
 *   re-computed pp_SecureHash over the returned pp_ fields.
 *
 * The secure hash is HMAC-SHA256 over all fields starting "pp" (including
 * ppmpf_1..5 when present), sorted alphabetically by field name, joined with
 * "&", prepended by the Integrity Salt, keyed with the UTF-8 salt.
 */
final class JazzCashGatewayDriver implements PaymentGatewayInterface
{
    public const CODE_SUCCESS = '000';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'jazzcash';
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && filled($this->config['merchant_id'] ?? null)
            && filled($this->config['password'] ?? null)
            && filled($this->config['integrity_salt'] ?? null);
    }

    public function initiate(array $params): array
    {
        $fields = [
            'pp_Version' => '1.1',
            'pp_TxnType' => 'MWALLET',
            'pp_Language' => 'EN',
            'pp_MerchantID' => (string) $this->config['merchant_id'],
            'pp_Password' => (string) $this->config['password'],
            'pp_TxnRefNo' => (string) $params['txn_ref_no'],
            'pp_Amount' => (string) $this->amountToMinorUnits((float) $params['amount']),
            'pp_TxnCurrency' => 'PKR',
            'pp_TxnDateTime' => now()->format('YmdHis'),
            'pp_TxnExpiryDateTime' => now()->addDay()->format('YmdHis'),
            'pp_BillReference' => (string) $params['bill_reference'],
            'pp_Description' => (string) $params['description'],
            'pp_ReturnURL' => (string) ($this->config['return_url'] ?? url('/api/v1/jazzcash/callback')),
        ];

        $fields['pp_SecureHash'] = $this->computeSignature($fields);

        return [
            'redirect_url' => $this->transactionUrl(),
            'fields' => $fields,
        ];
    }

    public function verifySignature(array $fields): bool
    {
        $provided = (string) ($fields['pp_SecureHash'] ?? '');

        if ($provided === '') {
            return false;
        }

        // The gateway emits lowercase hex in its docs and examples, but has
        // accepted uppercase in the wild — compare case-insensitively.
        return hash_equals($this->computeSignature($fields), strtolower(trim($provided)));
    }

    public function computeSignature(array $fields): string
    {
        $salt = (string) ($this->config['integrity_salt'] ?? '');

        $hashFields = [];

        foreach ($fields as $name => $value) {
            if ($name === 'pp_SecureHash') {
                continue;
            }

            // Official rule: every field beginning with "pp" is hashed
            // (this includes the ppmpf_1..5 custom fields).
            if (! str_starts_with($name, 'pp')) {
                continue;
            }

            $hashFields[$name] = (string) $value;
        }

        ksort($hashFields, SORT_STRING);

        $message = $salt.'&'.implode('&', array_values($hashFields));

        return hash_hmac('sha256', $message, $salt);
    }

    public function transactionUrl(): string
    {
        $key = ($this->config['env'] ?? 'sandbox') === 'production' ? 'production_url' : 'sandbox_url';

        return (string) ($this->config[$key] ?? '');
    }

    public function amountToMinorUnits(float $rupees): int
    {
        return (int) round($rupees * 100);
    }

    public function amountFromMinorUnits(string|int $paisa): float
    {
        return round(((float) $paisa) / 100, 2);
    }
}
