<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->randomFloat(2, 50, 900);
        $tax = round($subtotal * 0.13, 2);
        $total = round($subtotal + $tax, 2);

        return [
            'invoice_number' => 'INV-'.substr(Str::random(8), 0, 8),
            'patient_id' => Patient::factory(),
            'patient_name' => fn (array $attributes) => Patient::find($attributes['patient_id'])?->name,
            'status' => $this->faker->randomElement(['generated', 'paid']),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'amount_paid' => 0,
            'balance_due' => fn (array $attributes) => round($attributes['total'] - $attributes['amount_paid'], 2),
            'issued_at' => $this->faker->dateTimeBetween('-3 months', 'now')->format('Y-m-d H:i:s'),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => 'paid',
            'amount_paid' => fn (array $attributes) => round($attributes['total'], 2),
        ]);
    }
}
