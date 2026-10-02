<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->create()->id,
            'position' => $this->faker->randomElement(['Receptionist', 'Accountant', 'Pharmacist', 'Lab Technician']),
            'department' => $this->faker->randomElement(['reception', 'pharmacy', 'lab', 'administration']),
            'employee_id' => $this->faker->unique()->numerify('EMP-####'),
            'salary' => $this->faker->randomFloat(2, 30000, 150000),
            'is_active' => true,
        ];
    }
}
