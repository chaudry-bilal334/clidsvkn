<?php

namespace App\Services;

use App\Contracts\Messaging\MessagingInterface;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Messaging\WhatsAppCloudApiDriver;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class NotificationService
{
    /**
     * @var array<string, MessagingInterface>|null
     */
    private ?array $resolvedDrivers = null;

    /**
     * @param  array<string, array<string, mixed>>|null  $drivers
     */
    public function __construct(private readonly ?array $drivers = null) {}

    /**
     * Deliver a message to the user across every enabled messaging driver,
     * gated by the user's broadcast/communication consent for the channel.
     * Unconfigured or disabled drivers fall back to a logged audit record.
     *
     * Each driver resolves its own recipient: the phone-based drivers use the
     * user's phone number, the email driver uses their email address. When a
     * message is addressed to a channel whose drivers have no usable address
     * for this user, the whole send is skipped as a missing recipient rather
     * than being half-delivered.
     *
     * When a WhatsApp template name is supplied, the WhatsApp driver sends a
     * pre-approved Meta template message instead of free-form text (required
     * for business-initiated messages outside the 24-hour customer-service
     * window); every other driver keeps sending the rendered free text.
     *
     * @param  array<int, string|int|float|bool|null>  $whatsappTemplateParameters
     * @return array<string, string> driver name => "skipped"|"logged"|"sent"|"failed"
     */
    public function send(
        User $user,
        string $channel,
        string $title,
        string $body,
        ?string $recipient = null,
        ?int $templateId = null,
        ?string $whatsappTemplateName = null,
        string $whatsappTemplateLanguage = 'en',
        array $whatsappTemplateParameters = [],
    ): array {
        if (! $user->consentsTo($channel)) {
            $this->record($user, $channel, $title, $body, 'skipped', 'user_consent_opt_out', $templateId);

            return ['skipped' => $channel];
        }

        $recipients = $this->recipientsFor($channel, $user, $recipient);

        if ($recipients === []) {
            $this->record($user, $channel, $title, $body, 'skipped', 'missing_recipient', $templateId);

            return ['skipped' => 'missing_recipient'];
        }

        $deliveries = [];

        foreach ($recipients as $name => $to) {
            $driver = $this->drivers()[$name] ?? null;

            if ($driver === null) {
                continue;
            }

            if (! $driver->isEnabled()) {
                Log::info("[messaging] {$channel} unconfigured; logging instead for {$user->email}");
                $this->record($user, $channel, $title, $body, 'logged', null, $templateId);

                $deliveries[$name] = 'logged';

                continue;
            }

            try {
                $delivered = $name === 'whatsapp' && filled($whatsappTemplateName) && $driver instanceof WhatsAppCloudApiDriver
                    ? $driver->sendTemplate($to, (string) $whatsappTemplateName, $whatsappTemplateLanguage, $whatsappTemplateParameters)
                    : $driver->send($to, $body, ['subject' => $title, 'title' => $title]);
            } catch (\Throwable $exception) {
                Log::error("[messaging] {$name} delivery error for {$user->email}: {$exception->getMessage()}");
                $this->record($user, $channel, $title, $body, 'failed', null, $templateId);

                $deliveries[$name] = 'failed';

                continue;
            }

            $this->record($user, $channel, $title, $body, $delivered ? 'sent' : 'failed', null, $templateId);
            $deliveries[$name] = $delivered ? 'sent' : 'failed';
        }

        return $deliveries;
    }

    /**
     * Send a message using an approved notification template: renders the
     * placeholders and, for email, the subject line, then delivers through
     * the same consent/driver path as free-text sends.
     *
     * A template that has not been approved by the clinical/ops reviewer is
     * refused so unapproved copy can never leave the system.
     *
     * Supplying a WhatsApp template name makes the WhatsApp driver deliver a
     * pre-approved Meta template (with the given parameters) instead of free
     * text — required for proactive reminders outside Meta's 24-hour window.
     *
     * @param  array<string, scalar|null>  $data
     * @param  array<int, string|int|float|bool|null>  $whatsappTemplateParameters
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function sendFromTemplate(
        User $user,
        NotificationTemplate $template,
        array $data,
        ?string $recipient = null,
        ?string $whatsappTemplateName = null,
        string $whatsappTemplateLanguage = 'en',
        array $whatsappTemplateParameters = [],
    ): array {
        if (! $template->is_approved) {
            throw ValidationException::withMessages([
                'template_id' => ['Only approved notification templates can be sent.'],
            ]);
        }

        $title = $template->renderSubject($data) ?? $template->name;
        $body = $template->renderBody($data);

        return $this->send($user, $template->channel, $title, $body, $recipient, $template->id, $whatsappTemplateName, $whatsappTemplateLanguage, $whatsappTemplateParameters);
    }

    /**
     * Resolve a destination address for every driver that carries the given
     * logical channel. Drivers whose address is blank for this user are left
     * out, so a send is only attempted where a real address exists.
     *
     * @return array<string, string> driver name => address
     */
    private function recipientsFor(string $channel, User $user, ?string $recipient): array
    {
        $recipients = [];

        foreach ($this->drivers() as $name => $driver) {
            if (! $driver->handlesChannel($channel)) {
                continue;
            }

            $to = $driver->recipientFor($user, $recipient);

            if (filled($to)) {
                $recipients[$name] = $to;
            }
        }

        return $recipients;
    }

    /**
     * @return array<string, MessagingInterface>
     */
    private function drivers(): array
    {
        if ($this->resolvedDrivers !== null) {
            return $this->resolvedDrivers;
        }

        $config = $this->drivers ?? config('messaging.drivers', []);

        $this->resolvedDrivers = [];

        foreach ($config as $name => $settings) {
            $class = $settings['driver'] ?? null;

            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $this->resolvedDrivers[$name] = app($class, ['config' => $settings]);
        }

        return $this->resolvedDrivers;
    }

    private function record(User $user, string $channel, string $title, string $body, string $status, ?string $reason = null, ?int $templateId = null): void
    {
        NotificationLog::create([
            'user_id' => $user->id,
            'channel' => $channel,
            'template_id' => $templateId,
            'title' => $title,
            'body' => $body,
            'status' => $status,
        ]);

        if ($reason !== null) {
            Log::info("[messaging] {$channel} {$status} for {$user->email}: {$reason}");
        }
    }
}
