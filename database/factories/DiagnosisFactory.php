<?php

namespace Database\Factories;

use App\Models\Diagnosis;
use App\Models\Encounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Diagnosis>
 */
class DiagnosisFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'encounter_id' => Encounter::factory(),
            'icd_code' => $this->faker->randomElement(['R50.9', 'I10', 'E11.9', 'J06.9', 'K29.7', 'G43.9']),
            'description' => $this->faker->sentence(5),
            'recorded_by' => null,
        ];
    }
}
