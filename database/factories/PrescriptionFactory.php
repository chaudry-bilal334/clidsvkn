<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prescription_number' => 'RX-'.substr(Str::random(8), 0, 8),
            'patient_id' => Patient::factory(),
            'patient_name' => fn (array $attributes) => Patient::find($attributes['patient_id'])?->name,
            'doctor_id' => User::factory(),
            'doctor_name' => fn (array $attributes) => User::find($attributes['doctor_id'])?->name,
            'doctor_license_no' => $this->faker->optional(0.7)->numerify('LIC-#####'),
            'appointment_id' => null,
            'status' => $this->faker->randomElement(['pending', 'dispensed']),
            'notes' => $this->faker->optional(0.6)->sentence(10),
            'issued_at' => $this->faker->dateTimeBetween('-3 months', 'now')->format('Y-m-d H:i:s'),
        ];
    }
}
