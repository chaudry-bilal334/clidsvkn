<?php

namespace Database\Factories;

use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryBatch>
 */
class InventoryBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(1, 300);

        return [
            'inventory_item_id' => InventoryItem::factory(),
            'batch_number' => 'B-'.strtoupper($this->faker->bothify('####??')),
            'expiry_date' => $this->faker->dateTimeBetween('now', '+2 years')->format('Y-m-d'),
            'initial_quantity' => $quantity,
            'current_quantity' => $quantity,
        ];
    }

    /**
     * Mark the batch as expired so it is excluded from dispensing.
     */
    public function expired(string $expiryDate = '2000-01-01'): static
    {
        return $this->state(fn () => [
            'expiry_date' => $expiryDate,
        ]);
    }
}
