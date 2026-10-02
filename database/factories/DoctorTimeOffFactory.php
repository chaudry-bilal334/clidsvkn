<?php

namespace Database\Factories;

use App\Models\DoctorTimeOff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorTimeOff>
 */
class DoctorTimeOffFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doctor_id' => User::factory(),
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(2)->toDateString(),
            'reason' => $this->faker->optional(0.7)->sentence(3),
        ];
    }

    public function covering(string $date): static
    {
        return $this->state(fn () => [
            'start_date' => $date,
            'end_date' => $date,
        ]);
    }
}
