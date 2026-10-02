<?php

namespace Database\Factories;

use App\Models\InventoryBatch;
use App\Models\StockAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAlert>
 */
class StockAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_batch_id' => InventoryBatch::inRandomOrder()->first()?->id,
            'threshold_days' => $this->faker->numberBetween(0, 90),
            'alert_type' => $this->faker->randomElement(['expiring', 'expired']),
            'message' => $this->faker->optional()->text(80),
        ];
    }
}
