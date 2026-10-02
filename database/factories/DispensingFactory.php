<?php

namespace Database\Factories;

use App\Models\Dispensing;
use App\Models\InventoryBatch;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispensing>
 */
class DispensingFactory extends Factory
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
            'inventory_batch_id' => InventoryBatch::factory(),
            'quantity_dispensed' => $this->faker->numberBetween(1, 60),
            'dispensed_by' => User::factory()->state(['role' => 'Pharmacist']),
        ];
    }
}
