<?php

namespace App\Contracts\Messaging;

use App\Models\User;

/**
 * A transport for delivering short text messages (SMS / WhatsApp / email) to a
 * recipient. Implementations are thin adapters over the provider HTTP APIs or
 * Laravel's mailer.
 */
interface MessagingInterface
{
    /**
     * The logical channel name, e.g. "sms", "whatsapp" or "email".
     */
    public function name(): string;

    /**
     * Whether the provider credentials are present and the driver is marked
     * active in configuration. When false, delivery falls back to logging.
     */
    public function isEnabled(): bool;

    /**
     * Whether this driver carries messages addressed to the given logical
     * channel. A message on an email channel is never pushed through the phone
     * transports (and vice versa), so a clinic cannot accidentally start
     * emailing patients from a notification that was written as an SMS.
     */
    public function handlesChannel(string $channel): bool;

    /**
     * Resolve the address this driver delivers to for the given user. Phone
     * transports use the user's phone number; the email transport uses their
     * email address. An explicitly supplied recipient always wins.
     */
    public function recipientFor(User $user, ?string $recipient = null): ?string;

    /**
     * Deliver a message. Returns true when the provider accepted it.
     *
     * @param  array<string, mixed>  $options
     */
    public function send(string $to, string $body, array $options = []): bool;
}
