<?php

namespace Database\Factories;

use App\Models\Allergen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Allergen>
 */
class AllergenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['Penicillin', 'Sulfonamide', 'NSAID']),
            'family' => $this->faker->randomElement(['penicillin', 'sulfa', 'nsaid']),
            'aliases' => $this->faker->randomElement([['amoxicillin'], ['sulfamethoxazole'], ['ibuprofen']]),
            'is_approved' => false,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    /**
     * Mark the allergen as approved by the clinic's qualified clinical lead.
     */
    public function approved(): static
    {
        return $this->state(fn () => [
            'is_approved' => true,
            'approved_at' => now(),
        ]);
    }
}
