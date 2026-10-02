<?php

namespace Database\Factories;

use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => NotificationTemplate::CHANNEL_SMS,
            'name' => 'Appointment reminder',
            'subject' => null,
            'body' => 'Dear {{patient_name}}, your appointment with {{doctor_name}} is on {{appointment_date}} at {{appointment_time}}.',
            'is_approved' => false,
            'approved_by' => null,
            'approved_at' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Mark the template as approved by the given user.
     */
    public function approved(?User $approver = null): static
    {
        return $this->state(fn (): array => [
            'is_approved' => true,
            'approved_by' => ($approver ?? User::factory()->create())->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * Build an email template with a subject line.
     */
    public function email(): static
    {
        return $this->state(fn (): array => [
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'subject' => 'Your appointment is confirmed',
            'body' => 'Dear {{patient_name}}, your visit on {{appointment_date}} at {{appointment_time}} is confirmed.',
        ]);
    }
}
