<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Statuses are drawn from the PRD §6 lifecycle only — a factory that
     * produced values outside the vocabulary would create rows the state
     * machine can never move.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'doctor_id' => User::factory(),
            'scheduled_at' => $this->faker->dateTimeBetween('-1 week', '+2 weeks')->format('Y-m-d H:i:s'),
            'status' => $this->faker->randomElement([
                Appointment::STATUS_BOOKED,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_ARRIVED,
                Appointment::STATUS_WAITING,
                Appointment::STATUS_IN_CONSULTATION,
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_NO_SHOW,
            ]),
            'status_changed_at' => now(),
            'reason' => $this->faker->sentence(4),
            'notes' => $this->faker->optional(0.5)->sentence(8),
        ];
    }

    /**
     * A future appointment that still holds its slot.
     */
    public function booked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Appointment::STATUS_BOOKED,
            'scheduled_at' => $this->faker->dateTimeBetween('+1 day', '+2 weeks')->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * An appointment that has released its slot.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Appointment::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Patient requested',
        ]);
    }
}
