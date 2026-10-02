<?php

namespace App\Handlers\Alert;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AlertNotificationHandler
{
    public function handle(Throwable $e, ?Request $request = null): void
    {
        $slackWebhook = config('services.slack.webhook_url', env('SLACK_WEBHOOK_URL'));
        $pagerDutyKey = config('services.pagerduty.integration_key', env('PAGERDUTY_INTEGRATION_KEY'));

        $hasChannel = ! empty($slackWebhook) || ! empty($pagerDutyKey);

        if (! app()->environment('production', 'staging') || ! $hasChannel) {
            return;
        }

        $request ??= request();

        $severity = $this->determineSeverity($e);
        $title = $this->buildTitle($e, $severity);
        $message = $this->buildMessage($e, $request);

        if (! empty($slackWebhook)) {
            $this->sendToSlack($slackWebhook, $title, $message, $severity);
        }

        if (! empty($pagerDutyKey)) {
            $this->sendToPagerDuty($pagerDutyKey, $title, $message, $severity);
        }

        Log::critical("ALERT: {$title}", [
            'message' => $message,
            'exception' => get_class($e),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'request_uri' => $request->getRequestUri(),
            'user_ip' => $request->ip(),
        ]);
    }

    protected function determineSeverity(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        $criticalPatterns = ['database', 'connection', 'failed', 'stall', 'authentication', 'authorization', 'security'];
        foreach ($criticalPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return 'critical';
            }
        }

        return 'warning';
    }

    protected function buildTitle(Throwable $e, string $severity): string
    {
        $class = class_basename($e);
        $message = $e->getMessage();

        if (strlen($message) > 100) {
            $message = substr($message, 0, 100).'...';
        }

        return "[{$severity}] {$class}: {$message}";
    }

    protected function buildMessage(Throwable $e, Request $request): string
    {
        $lines = [
            "Exception: {$e->getMessage()}",
            'Class: '.get_class($e),
            "File: {$e->getFile()}",
            "Line: {$e->getLine()}",
            "URI: {$request->getRequestUri()}",
            "IP: {$request->ip()}",
        ];

        $trace = $e->getTraceAsString();
        if ($trace) {
            $lines[] = "Trace:\n".substr($trace, 0, 500).'...';
        }

        return implode("\n", $lines);
    }

    protected function sendToSlack(string $webhookUrl, string $title, string $message, string $severity): void
    {
        $payload = [
            'text' => $title,
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $title,
                        'emoji' => true,
                    ],
                ],
                [
                    'type' => 'divider',
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "```{$message}```",
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'plain_text',
                            'text' => "Severity: {$severity} | App: ".config('app.name', 'ClinicSuite'),
                            'emoji' => true,
                        ],
                    ],
                ],
            ],
        ];

        $this->postWebhook($webhookUrl, $payload);
    }

    protected function sendToPagerDuty(string $integrationKey, string $title, string $message, string $severity): void
    {
        $pdSeverity = $severity === 'critical' ? 'critical' : 'warning';

        $payload = [
            'routing_key' => $integrationKey,
            'event_action' => 'trigger',
            'payload' => [
                'summary' => $title,
                'source' => config('app.name', 'clinic-laravel-api'),
                'severity' => $pdSeverity,
                'component' => 'laravel-api',
                'class' => 'application_error',
                'custom_details' => [
                    'message' => $message,
                ],
            ],
        ];

        $this->postWebhook('https://events.pagerduty.com/v2/enqueue', $payload);
    }

    protected function postWebhook(string $url, array $payload): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
