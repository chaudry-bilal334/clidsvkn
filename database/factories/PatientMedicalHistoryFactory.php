<?php

namespace Database\Factories;

use App\Models\Allergen;
use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientMedicalHistory>
 */
class PatientMedicalHistoryFactory extends Factory
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
            'category' => PatientMedicalHistory::CATEGORY_ALLERGY,
            'allergen_id' => null,
            'term' => null,
            'reaction' => null,
            'severity' => PatientMedicalHistory::SEVERITY_UNKNOWN,
            'onset_date' => null,
            'is_active' => true,
            'recorded_by' => null,
            'notes' => $this->faker->optional(0.5)->sentence(6),
        ];
    }

    /**
     * Mark the entry as a specific, active allergy against an allergen.
     */
    public function allergyTo(Allergen $allergen): static
    {
        return $this->state(fn () => [
            'category' => PatientMedicalHistory::CATEGORY_ALLERGY,
            'allergen_id' => $allergen->id,
            'severity' => PatientMedicalHistory::SEVERITY_MODERATE,
            'term' => $allergen->name,
        ]);
    }

    /**
     * Record a non-allergy medical condition.
     */
    public function condition(string $term): static
    {
        return $this->state(fn () => [
            'category' => PatientMedicalHistory::CATEGORY_CONDITION,
            'allergen_id' => null,
            'term' => $term,
            'reaction' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
