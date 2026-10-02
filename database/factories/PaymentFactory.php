<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount' => $this->faker->randomFloat(2, 20, 500),
            'method' => $this->faker->randomElement(Payment::METHODS),
            'reference' => 'PAY-'.$this->faker->numerify('######'),
            'paid_at' => $this->faker->dateTimeBetween('-3 months', 'now')->format('Y-m-d H:i:s'),
        ];
    }
}
