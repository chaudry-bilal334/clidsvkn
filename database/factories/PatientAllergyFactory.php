<?php

namespace Database\Factories;

use App\Models\Allergen;
use App\Models\Patient;
use App\Models\PatientAllergy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientAllergy>
 */
class PatientAllergyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'allergen_id' => Allergen::factory(),
            'reaction' => $this->faker->randomElement(['Rash', 'Anaphylaxis', 'Nausea', 'Itching']),
            'severity' => $this->faker->randomElement(['mild', 'moderate', 'severe', 'life_threatening']),
            'is_active' => true,
            'first_reaction_at' => $this->faker->date(),
        ];
    }
}
