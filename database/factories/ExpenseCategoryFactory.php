<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => $this->faker->randomElement([
                'Utilities', 'Rent', 'Salaries', 'Medical Supplies',
                'Office Supplies', 'Marketing', 'Equipment Maintenance',
                'Insurance', 'Professional Fees', 'Miscellaneous',
            ]),
            'description' => $this->faker->optional(0.5)->sentence(),
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(0, 100),
        ];
    }
}
