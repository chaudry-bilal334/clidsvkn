<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Encounter;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Followup>
 */
class FollowupFactory extends Factory
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
            'patient_id' => Patient::factory(),
            'lead_id' => null,
            'encounter_id' => null,
            'created_by' => null,
            'assigned_to' => null,
            'due_at' => $this->faker->dateTimeBetween('+1 day', '+2 weeks')->format('Y-m-d H:i:s'),
            'type' => Followup::TYPE_GENERAL,
            'status' => Followup::STATUS_PENDING,
            'notes' => $this->faker->optional(0.6)->sentence(8),
            'completed_at' => null,
            'completed_by' => null,
        ];
    }

    public function forLead(): static
    {
        return $this->state(fn () => [
            'patient_id' => null,
            'lead_id' => Lead::factory(),
        ]);
    }

    public function forEncounter(Encounter $encounter): static
    {
        return $this->state(fn () => [
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->id,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['due_at' => now()->subDay()->format('Y-m-d H:i:s')]);
    }

    public function due(): static
    {
        return $this->state(fn () => ['due_at' => now()->addDay()->format('Y-m-d H:i:s')]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => Followup::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => Followup::STATUS_CANCELLED]);
    }
}
