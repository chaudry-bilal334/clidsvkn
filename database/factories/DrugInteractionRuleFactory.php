<?php

namespace Database\Factories;

use App\Models\DrugInteractionRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrugInteractionRule>
 */
class DrugInteractionRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'medicine_a' => $this->faker->randomElement(['Warfarin', 'Sildenafil']),
            'medicine_b' => $this->faker->randomElement(['Ibuprofen', 'Aspirin']),
            'aliases_a' => null,
            'aliases_b' => null,
            'severity' => DrugInteractionRule::SEVERITY_MODERATE,
            'guidance' => 'Potential interaction detected; adjust dosing or monitor for adverse effects.',
            'is_approved' => false,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    /**
     * Lock the two medicines to specific values for deterministic assertions.
     */
    public function between(string $medicineA, string $medicineB): static
    {
        return $this->state(fn () => [
            'medicine_a' => $medicineA,
            'medicine_b' => $medicineB,
        ]);
    }

    /**
     * Mark the rule as approved by the clinic's qualified clinical lead.
     */
    public function approved(): static
    {
        return $this->state(fn () => [
            'is_approved' => true,
            'approved_at' => now(),
        ]);
    }
}
