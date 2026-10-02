<?php

namespace Database\Factories;

use App\Models\ClinicalDecisionSupport;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalDecisionSupport>
 */
class ClinicalDecisionSupportFactory extends Factory
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
            'doctor_id' => User::factory()->state(['role' => 'Doctor']),
            'trigger_type' => $this->faker->randomElement([
                ClinicalDecisionSupport::TRIGGER_DRUG_INTERACTION,
                ClinicalDecisionSupport::TRIGGER_ALLERGY_WARNING,
                ClinicalDecisionSupport::TRIGGER_DOSAGE_CHECK,
            ]),
            'recommendation' => $this->faker->sentence(10),
            'is_acknowledged' => false,
        ];
    }

    public function acknowledged(): static
    {
        return $this->state(fn () => ['is_acknowledged' => true]);
    }
}
