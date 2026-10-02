<?php

namespace Database\Factories;

use App\Models\DispenseGroup;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DispenseGroup>
 */
class DispenseGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prescription_id' => Prescription::factory(),
            'invoice_id' => null,
            'created_by' => User::factory(),
        ];
    }
}
