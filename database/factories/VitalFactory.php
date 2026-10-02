<?php

namespace Database\Factories;

use App\Models\Encounter;
use App\Models\Vital;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vital>
 */
class VitalFactory extends Factory
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
            'blood_pressure' => $this->faker->randomElement(['110/70', '120/80', '128/84', '135/90']),
            'temperature' => $this->faker->randomFloat(1, 36.0, 38.5),
            'heart_rate' => $this->faker->numberBetween(58, 110),
            'weight' => $this->faker->randomFloat(2, 45.00, 120.00),
            'recorded_by' => null,
        ];
    }
}
