<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\MessagingInterface;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class TwilioSmsDriver implements MessagingInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'sms';
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && filled($this->config['sid'] ?? null)
            && filled($this->config['token'] ?? null)
            && filled($this->config['from'] ?? null);
    }

    /**
     * SMS carries the phone-based notification channels. Topic channels such as
     * "appointments" are phone channels too, so anything that is not an
     * explicitly email-addressed channel is in scope here.
     */
    public function handlesChannel(string $channel): bool
    {
        return $channel !== NotificationTemplate::CHANNEL_EMAIL;
    }

    public function recipientFor(User $user, ?string $recipient = null): ?string
    {
        return filled($recipient) ? $recipient : ($user->phone ?: null);
    }

    public function send(string $to, string $body, array $options = []): bool
    {
        $config = $this->config;
        $sid = (string) $config['sid'];

        $response = Http::withBasicAuth($sid, (string) $config['token'])
            ->asForm()
            ->post(
                "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json",
                [
                    'To' => $to,
                    'From' => (string) $config['from'],
                    'Body' => $body,
                ],
            );

        /** @var Response $response */
        return $response->successful();
    }
}
