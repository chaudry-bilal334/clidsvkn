<?php

namespace Database\Factories;

use App\Models\DosageRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DosageRule>
 */
class DosageRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'medicine_name' => $this->faker->randomElement(['Paracetamol', 'Ibuprofen', 'Amoxicillin']),
            'aliases' => null,
            'max_daily_mg' => $this->faker->numberBetween(1000, 4000),
            'is_approved' => false,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    /**
     * Lock the medicine and its daily maximum for deterministic assertions.
     */
    public function forMedicine(string $medicineName, float|int $maxDailyMg): static
    {
        return $this->state(fn () => [
            'medicine_name' => $medicineName,
            'max_daily_mg' => $maxDailyMg,
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
