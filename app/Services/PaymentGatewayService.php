<?php

namespace App\Services;

use App\Contracts\Payment\PaymentGatewayInterface;

/**
 * Resolves payment gateway drivers from config, mirroring how the messaging
 * channels are wired (NotificationService). Drivers are instantiated per
 * gateway with their credentials and never touch the provider API unless
 * enabled and configured.
 */
class PaymentGatewayService
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    private array $resolved = [];

    /**
     * @param  array<string, array<string, mixed>>|null  $drivers
     */
    public function __construct(private readonly ?array $drivers = null) {}

    public function driver(string $name): ?PaymentGatewayInterface
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $config = $this->drivers ?? config('payments.drivers', []);
        $settings = $config[$name] ?? null;
        $class = is_array($settings) ? ($settings['driver'] ?? null) : null;

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        return $this->resolved[$name] = app($class, ['config' => $settings]);
    }
}
