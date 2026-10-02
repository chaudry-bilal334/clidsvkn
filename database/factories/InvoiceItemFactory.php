<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = $this->faker->randomFloat(1, 1, 4);
        $unitPrice = $this->faker->randomFloat(2, 15, 250);

        return [
            'invoice_id' => Invoice::factory(),
            'description' => $this->faker->randomElement([
                'Consultation',
                'X-Ray Exam',
                'Blood Panel',
                'Prescription Refill',
                'Follow-up Visit',
            ]),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => round($quantity * $unitPrice, 2),
        ];
    }
}
