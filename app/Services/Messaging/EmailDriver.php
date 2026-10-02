<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\MessagingInterface;
use App\Mail\ClinicNotificationMail;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email transport for approved notification templates.
 *
 * Unlike SMS/WhatsApp this driver addresses a user by their email address
 * rather than their phone number, so it resolves its own recipient instead of
 * reusing the phone-based default in NotificationService. It is deliberately
 * disabled unless the clinic has both switched it on and pointed Laravel's
 * mailer at a transport that actually delivers (anything other than the "log"
 * or "array" test transports, which would silently swallow patient messages).
 */
final class EmailDriver implements MessagingInterface
{
    /**
     * Mailer names that never leave the host. Treating them as "unconfigured"
     * keeps the logged fallback in NotificationService authoritative instead of
     * reporting a message as sent when it was only written to a log file.
     *
     * @var array<int, string>
     */
    private const NON_DELIVERING_MAILERS = ['log', 'array'];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return NotificationTemplate::CHANNEL_EMAIL;
    }

    public function isEnabled(): bool
    {
        if (! (bool) ($this->config['enabled'] ?? false)) {
            return false;
        }

        if (blank($this->fromAddress())) {
            return false;
        }

        return ! in_array($this->mailer(), self::NON_DELIVERING_MAILERS, true);
    }

    /**
     * Email only carries messages explicitly addressed to the email channel.
     */
    public function handlesChannel(string $channel): bool
    {
        return $channel === NotificationTemplate::CHANNEL_EMAIL;
    }

    public function send(string $to, string $body, array $options = []): bool
    {
        $subject = trim((string) ($options['subject'] ?? ''));

        if ($subject === '') {
            $subject = (string) ($options['title'] ?? 'Clinic notification');
        }

        try {
            Mail::mailer($this->mailer())->send(
                new ClinicNotificationMail(
                    recipient: $to,
                    subjectLine: $subject,
                    body: $body,
                    fromAddress: $this->fromAddress(),
                    fromName: $this->fromName(),
                ),
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Resolve the address this driver delivers to. Email uses the user's email
     * address; the phone-based drivers keep using the phone number.
     */
    public function recipientFor(User $user, ?string $recipient = null): ?string
    {
        return filled($recipient) ? $recipient : ($user->email ?: null);
    }

    private function mailer(): string
    {
        return (string) ($this->config['mailer'] ?? config('mail.default', 'log'));
    }

    private function fromAddress(): string
    {
        return (string) ($this->config['from_address'] ?? config('mail.from.address', ''));
    }

    private function fromName(): string
    {
        return (string) ($this->config['from_name'] ?? config('mail.from.name', ''));
    }
}
