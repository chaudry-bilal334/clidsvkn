<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->optional(0.8)->phoneNumber(),
            'service_interest' => $this->faker->optional(0.6)->randomElement(['General Consultation', 'Dental Care', 'Cardiology Consultation']),
            'preferred_doctor_id' => null,
            'preferred_date' => $this->faker->optional(0.6)->dateTimeBetween('+1 day', '+2 weeks')?->format('Y-m-d'),
            'preferred_time' => $this->faker->optional(0.6)->time('H:i'),
            'source' => 'website',
            'status' => Lead::STATUS_NEW,
            'notes' => $this->faker->optional(0.5)->sentence(8),
            'assigned_to' => null,
        ];
    }

    public function stageNew(): static
    {
        return $this->state(fn () => ['status' => Lead::STATUS_NEW]);
    }

    public function contacted(): static
    {
        return $this->state(fn () => ['status' => Lead::STATUS_CONTACTED]);
    }

    public function qualified(): static
    {
        return $this->state(fn () => ['status' => Lead::STATUS_QUALIFIED]);
    }

    public function converted(): static
    {
        return $this->state(fn () => ['status' => Lead::STATUS_CONVERTED]);
    }

    public function closedLost(): static
    {
        return $this->state(fn () => ['status' => Lead::STATUS_CLOSED_LOST]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assigned_to' => $user->id]);
    }
}
