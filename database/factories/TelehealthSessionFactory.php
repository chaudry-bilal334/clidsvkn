<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelehealthSession>
 */
class TelehealthSessionFactory extends Factory
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
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the session has ended.
     */
    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ended',
        ]);
    }
}
