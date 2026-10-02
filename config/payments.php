<?php

use App\Services\Payments\JazzCashGatewayDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | Each entry wires a logical gateway ("jazzcash") to a driver class and its
    | merchant credentials, mirroring how the messaging channels are wired in
    | config/messaging.php. Drivers only build/verify real provider requests
    | when the gateway is enabled AND credentials are present; otherwise the
    | initiate endpoint refuses gracefully and nothing reaches the provider.
    |
    */

    'drivers' => [
        'jazzcash' => [
            'driver' => JazzCashGatewayDriver::class,
            'enabled' => (bool) env('JAZZCASH_ENABLED', false),
            'env' => env('JAZZCASH_ENV', 'sandbox'),
            'merchant_id' => env('JAZZCASH_MERCHANT_ID'),
            'password' => env('JAZZCASH_PASSWORD'),
            'integrity_salt' => env('JAZZCASH_INTEGRITY_SALT'),
            'sandbox_url' => env('JAZZCASH_SANDBOX_URL', 'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform'),
            'production_url' => env('JAZZCASH_PRODUCTION_URL', 'https://payments.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform'),
            'return_url' => env('JAZZCASH_RETURN_URL'),
        ],
    ],

    /*
    | The page the browser is sent to after the gateway posts the transaction
    | result to the callback. Defaults to the SPA billing screen served from
    | this application (same origin as the API), with ?jazzcash=success|failed
    | and &ref=<txn ref> appended.
    */

    'return_redirect_url' => env('JAZZCASH_RETURN_REDIRECT_URL'),
];
