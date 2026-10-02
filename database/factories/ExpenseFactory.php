<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        $status = $this->faker->randomElement(['pending', 'paid', 'partial']);
        $approvedAt = $status !== 'pending' ? $this->faker->dateTimeBetween('-1 month', 'now') : null;
        $approvedBy = $approvedAt ? User::factory()->state(['role' => 'Accountant']) : null;
        $amount = $this->faker->randomFloat(2, 100, 50000);
        $tax = round($amount * $this->faker->randomFloat(2, 0, 0.18), 2);

        return [
            'branch_id' => Branch::factory(),
            'expense_category_id' => ExpenseCategory::factory(),
            'expense_number' => 'EXP-'.Str::upper(Str::random(8)),
            'expense_date' => $this->faker->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'vendor' => $this->faker->optional(0.7)->company(),
            'amount' => $amount,
            'tax' => $tax,
            'total' => round($amount + $tax, 2),
            'payment_status' => $status,
            'payment_method' => $this->faker->randomElement(['cash', 'bank_transfer', 'card', 'cheque']),
            'description' => $this->faker->sentence(),
            'notes' => $this->faker->optional(0.5)->sentence(),
            'created_by' => User::factory()->state(['role' => 'Accountant']),
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt,
        ];
    }
}
