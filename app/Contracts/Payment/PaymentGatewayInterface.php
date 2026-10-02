<?php

namespace App\Contracts\Payment;

/**
 * A payment gateway transport (JazzCash, ...). Implementations are thin
 * adapters over the provider's hosted-checkout / page-redirection APIs: they
 * build signed requests and verify the signature on the provider's callback.
 */
interface PaymentGatewayInterface
{
    /**
     * The logical gateway name, e.g. "jazzcash".
     */
    public function name(): string;

    /**
     * Whether the merchant credentials are present and the gateway is marked
     * active in configuration. When false, initiation is refused gracefully
     * (mirrors the messaging drivers' enabled check).
     */
    public function isEnabled(): bool;

    /**
     * Build the signed fields the client must POST to the provider's hosted
     * checkout page.
     *
     * @param  array<string, scalar|null>  $params
     * @return array{redirect_url: string, fields: array<string, string>}
     */
    public function initiate(array $params): array;

    /**
     * Verify the HMAC signature on a callback payload received from the
     * provider. This is the gate for marking a payment complete: an unverified
     * payload must never change invoice or payment state.
     *
     * @param  array<string, scalar|null>  $fields
     */
    public function verifySignature(array $fields): bool;
}
