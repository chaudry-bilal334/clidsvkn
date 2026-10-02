<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Doctor>
 */
class DoctorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->create(['role' => 'Doctor'])->id,
            'license_number' => $this->faker->unique()->numerify('PMC-#######'),
            'specialization' => $this->faker->randomElement(['General Medicine', 'Cardiology', 'Pediatrics']),
            'qualification' => $this->faker->randomElement(['MBBS', 'FCPS', 'MD']),
            'contact_phone' => $this->faker->phoneNumber(),
            'bio' => $this->faker->sentence(),
            'is_active' => true,
        ];
    }
}
