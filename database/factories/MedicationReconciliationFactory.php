<?php

namespace Database\Factories;

use App\Models\MedicationReconciliation;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicationReconciliation>
 */
class MedicationReconciliationFactory extends Factory
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
            'encounter_id' => null,
            'medicine_name' => $this->faker->randomElement(['Warfarin 5mg', 'Ibuprofen 200mg', 'Metformin 500mg']),
            'dosage' => $this->faker->randomElement(['5 mg', '200 mg', '500 mg']),
            'frequency' => $this->faker->randomElement(['once daily', 'twice daily', 'three times daily']),
            'source' => MedicationReconciliation::SOURCE_SELF_REPORTED,
            'is_active' => true,
            'reconciled_at' => now(),
            'reconciled_by' => null,
            'notes' => null,
        ];
    }

    /**
     * Record a specific medicine as currently active.
     */
    public function medicine(string $medicineName): static
    {
        return $this->state(fn () => [
            'medicine_name' => $medicineName,
            'is_active' => true,
        ]);
    }
}
