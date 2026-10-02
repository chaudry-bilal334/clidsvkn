<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'mrn' => 'MRN-'.Str::upper(Str::random(8)),
            // `patients.cnic_hash` is uniquely indexed, and email is unique too,
            // so the factory has to guarantee both rather than leave it to
            // chance — a colliding factory row would surface as a confusing
            // constraint violation in whatever test happened to build it.
            'cnic' => $this->faker->unique()->numerify('#####-#######-#'),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->numerify('+1 (555) 2##-####'),
            'date_of_birth' => $this->faker->date('Y-m-d', now()->subYears(18)->toDateString()),
            'gender' => $this->faker->randomElement(['male', 'female']),
            'address' => $this->faker->address(),
        ];
    }
}
