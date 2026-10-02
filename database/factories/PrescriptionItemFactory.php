<?php

namespace Database\Factories;

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriptionItem>
 */
class PrescriptionItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prescription_id' => Prescription::factory(),
            'medicine_name' => $this->faker->randomElement([
                'Amoxicillin 500mg',
                'Ibuprofen 200mg',
                'Lisinopril 10mg',
                'Metformin 850mg',
                'Azithromycin 250mg',
            ]),
            'dosage' => $this->faker->randomElement(['500 mg', '10 mg', '250 mg', '850 mg']),
            'frequency' => $this->faker->randomElement(['twice daily', 'once daily', 'every 8 hours']),
            'duration' => $this->faker->randomElement(['7 days', '10 days', '14 days', '30 days']),
            'instructions' => $this->faker->sentence(6),
            'quantity' => $this->faker->numberBetween(10, 60),
        ];
    }
}
