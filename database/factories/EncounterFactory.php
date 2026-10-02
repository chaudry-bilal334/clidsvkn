<?php

namespace Database\Factories;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Encounter>
 */
class EncounterFactory extends Factory
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
            'appointment_id' => null,
            'status' => Encounter::STATUS_DRAFT,
            'notes' => $this->faker->optional(0.6)->sentence(8),
        ];
    }
}
