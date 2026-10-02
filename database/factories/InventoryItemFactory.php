<?php

namespace Database\Factories;

use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => strtoupper(Str::random(8)),
            'item_name' => $this->faker->randomElement([
                'Paracetamol 500mg Tablets',
                'Saline Solution 500ml',
                'Blood Pressure Monitor',
                'Surgical Gloves (Box)',
                'Insulin Syringes',
            ]),
            'category' => $this->faker->randomElement(['Medicine', 'Supply', 'Equipment']),
            'unit_price' => $this->faker->randomFloat(2, 2, 120),
            'stock_quantity' => $this->faker->numberBetween(0, 300),
            'reorder_level' => 20,
            'last_restocked' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
        ];
    }

    /**
     * Reflect the item's stock_quantity in a matching non-expired ledger batch so
     * the derived/ledger totals stay consistent with the stored column.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (InventoryItem $item): void {
            $quantity = (float) $item->stock_quantity;

            InventoryBatch::create([
                'inventory_item_id' => $item->id,
                'batch_number' => 'B-'.strtoupper(Str::random(8)),
                'expiry_date' => now()->addYears(2)->toDateString(),
                'initial_quantity' => $quantity,
                'current_quantity' => $quantity,
            ]);
        });
    }

    public function lowStock(): static
    {
        return $this->state(fn () => [
            'stock_quantity' => $this->faker->numberBetween(0, 5),
            'reorder_level' => 20,
        ]);
    }
}
