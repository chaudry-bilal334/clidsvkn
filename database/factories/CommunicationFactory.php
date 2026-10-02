<?php

namespace Database\Factories;

use App\Models\Communication;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Communication>
 */
class CommunicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'user_id' => null,
            'channel' => $this->faker->randomElement(Communication::channels()),
            'summary' => $this->faker->sentence(6),
            'notes' => $this->faker->optional(0.5)->sentence(10),
            'occurred_at' => now(),
        ];
    }

    public function recordedBy(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }
}
