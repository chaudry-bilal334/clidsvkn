<?php

use App\Services\Messaging\EmailDriver;
use App\Services\Messaging\TwilioSmsDriver;
use App\Services\Messaging\WhatsAppCloudApiDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Messaging Channels
    |--------------------------------------------------------------------------
    |
    | Each entry wires a logical channel ("sms", "whatsapp", "email") to a
    | driver class and its provider credentials. Drivers only dispatch real
    | requests when the channel is enabled AND credentials are present;
    | otherwise the NotificationService falls back to logging the message.
    |
    | No provider is assumed: every channel ships disabled with empty
    | credentials, so selecting a provider is a config/credentials change and
    | never a code change. See DECISIONS_PENDING.md §9.1.
    |
    */

    'drivers' => [
        'sms' => [
            'driver' => TwilioSmsDriver::class,
            'enabled' => (bool) env('TWILIO_SMS_ENABLED', false),
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_AUTH_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],

        'whatsapp' => [
            'driver' => WhatsAppCloudApiDriver::class,
            'enabled' => (bool) env('WHATSAPP_CLOUD_API_ENABLED', false),
            'token' => env('WHATSAPP_CLOUD_API_TOKEN'),
            'phone_number_id' => env('WHATSAPP_CLOUD_API_PHONE_NUMBER_ID'),
            'api_version' => env('WHATSAPP_CLOUD_API_VERSION', 'v19.0'),
        ],

        /*
         * Email has no third-party provider of its own: it rides on Laravel's
         * configured mailer (see config/mail.php). It is reported as disabled
         * while the mailer is "log"/"array" so a clinic cannot mistake a
         * locally-swallowed message for a delivered one.
         */
        'email' => [
            'driver' => EmailDriver::class,
            'enabled' => (bool) env('CLINIC_EMAIL_ENABLED', false),
            'mailer' => env('MAIL_MAILER', 'log'),
            'from_address' => env('MAIL_FROM_ADDRESS'),
            'from_name' => env('MAIL_FROM_NAME'),
        ],
    ],
];
