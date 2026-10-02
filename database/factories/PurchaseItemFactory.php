<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseItem>
 */
class PurchaseItemFactory extends Factory
{
    protected $model = PurchaseItem::class;

    public function definition(): array
    {
        $quantity = $this->faker->randomFloat(2, 1, 100);
        $unitPrice = $this->faker->randomFloat(2, 10, 5000);
        $taxRate = $this->faker->randomFloat(2, 0, 18);
        $lineTotal = round($quantity * $unitPrice * (1 + $taxRate / 100), 2);

        return [
            'purchase_id' => Purchase::factory(),
            'inventory_item_id' => InventoryItem::factory(),
            'item_name' => $this->faker->word().' '.$this->faker->word(),
            'item_sku' => 'SKU-'.$this->faker->numerify('####'),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'line_total' => $lineTotal,
            'batch_number' => 'BATCH-'.$this->faker->numerify('######'),
            'expiry_date' => $this->faker->dateTimeBetween('+6 months', '+3 years')->format('Y-m-d'),
            'notes' => $this->faker->optional(0.5)->sentence(),
        ];
    }
}
