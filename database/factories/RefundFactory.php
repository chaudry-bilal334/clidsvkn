<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->paid(),
            'payment_id' => null,
            'amount' => $this->faker->randomFloat(2, 10, 500),
            'reason' => $this->faker->sentence(6),
            'processed_by' => User::factory()->state(['role' => 'Accountant']),
        ];
    }
}
