<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryTransaction>
 */
class InventoryTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $item = InventoryItem::factory()->create();

        $unitPrice = $this->faker->randomFloat(2, 2, 120);
        $quantity = $this->faker->numberBetween(1, 50);

        return [
            'inventory_item_id' => $item->id,
            'quantity' => $quantity,
            'type' => 'purchase',
            'notes' => $this->faker->sentence(5),
            'unit_price' => $unitPrice,
            'total_amount' => round($quantity * $unitPrice, 2),
            'reason' => $this->faker->randomElement(['restock', 'correction', 'physical count', 'damaged']),
        ];
    }
}
