<?php

namespace Database\Factories;

use App\Models\Consent;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consent>
 */
class ConsentFactory extends Factory
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
            'user_id' => User::factory(),
            'consent_type' => $this->faker->randomElement([
                'treatment',
                'data_processing',
                'communications',
                'billing',
                'lab_procedures',
                'pharmacy_dispensing',
                'telehealth',
            ]),
            'scope' => $this->faker->sentence(),
            'is_active' => true,
            'signed_at' => now()->toDateString(),
            'expires_at' => null,
            'withdrawn_by' => null,
            'withdrawn_at' => null,
        ];
    }
}
