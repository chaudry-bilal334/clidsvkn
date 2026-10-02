<?php

namespace Database\Factories;

use App\Models\LabOrder;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabOrder>
 */
class LabOrderFactory extends Factory
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
            'test_name' => $this->faker->randomElement([
                'Complete Blood Count',
                'Blood Glucose Fasting',
                'Cholesterol Panel',
                'Thyroid Stimulating Hormone',
                'Urinalysis',
            ]),
            'status' => $this->faker->randomElement(['pending', 'in_progress', 'completed']),
            'result' => $this->faker->optional(0.5)->bothify('Result ref ##-#?'),
            'recorded_at' => $this->faker->dateTimeBetween('-3 months', 'now')->format('Y-m-d H:i:s'),
            'sample_collected_at' => null,
            'sample_collected_by' => null,
            'sample_identifier' => null,
        ];
    }
}
