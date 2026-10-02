<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    public function definition(): array
    {
        $status = $this->faker->randomElement(['draft', 'ordered', 'received', 'cancelled']);
        $receivedAt = $status === 'received' ? $this->faker->dateTimeBetween('-1 month', 'now') : null;
        $receivedBy = $receivedAt ? User::factory() : null;

        return [
            'branch_id' => Branch::factory(),
            'supplier_id' => Supplier::factory(),
            'purchase_number' => 'PO-'.Str::upper(Str::random(8)),
            'purchase_date' => $this->faker->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'status' => $status,
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
            'notes' => $this->faker->optional(0.5)->sentence(),
            'created_by' => User::factory()->state(['role' => 'Pharmacist']),
            'received_by' => $receivedBy,
            'received_at' => $receivedAt,
        ];
    }
}
