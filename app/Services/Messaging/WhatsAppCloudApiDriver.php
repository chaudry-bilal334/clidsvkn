<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\MessagingInterface;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class WhatsAppCloudApiDriver implements MessagingInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'whatsapp';
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && filled($this->config['token'] ?? null)
            && filled($this->config['phone_number_id'] ?? null);
    }

    /**
     * WhatsApp, like SMS, is a phone channel. An explicitly email-addressed
     * notification is never pushed through it.
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
        $version = (string) ($this->config['api_version'] ?? 'v19.0');
        $phoneNumberId = (string) $this->config['phone_number_id'];

        $response = Http::withToken((string) $this->config['token'])
            ->acceptJson()
            ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $body,
                ],
            ]);

        /** @var Response $response */
        return $response->successful();
    }

    /**
     * Deliver a pre-approved WhatsApp Business message template. Meta requires
     * business-initiated (proactive) messages — such as appointment reminders —
     * to use an approved template whenever they fall outside the 24-hour
     * customer-service window; free-form text is rejected in that case.
     *
     * The template name must already exist and be approved in the WhatsApp
     * Business Account, and each parameter maps positionally to a {{1}}, {{2}},
     * ... placeholder in the template body.
     *
     * @param  array<int, string|int|float|bool|null>  $parameters
     */
    public function sendTemplate(string $to, string $templateName, string $languageCode, array $parameters = []): bool
    {
        $version = (string) ($this->config['api_version'] ?? 'v19.0');
        $phoneNumberId = (string) $this->config['phone_number_id'];

        $template = [
            'name' => $templateName,
            'language' => ['code' => $languageCode],
        ];

        if ($parameters !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    static fn (string|int|float|bool|null $value): array => ['type' => 'text', 'text' => (string) $value],
                    array_values($parameters),
                ),
            ]];
        }

        $response = Http::withToken((string) $this->config['token'])
            ->acceptJson()
            ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'template',
                'template' => $template,
            ]);

        /** @var Response $response */
        return $response->successful();
    }
}
