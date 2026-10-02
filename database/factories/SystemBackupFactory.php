<?php

namespace Database\Factories;

use App\Models\SystemBackup;
use Illuminate\Database\Eloquent\Factories\Factory;

class SystemBackupFactory extends Factory
{
    /**
     * The name of the factory.
     */
    protected $model = SystemBackup::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'filename' => 'backup-'.now()->format('YmdHis').'.sql.gz',
            'file_path' => 'backups/'.now()->format('YmdHis').'.sql.gz',
            'file_size' => fake()->numberBetween(1000000, 500000000),
            'status' => fake()->randomElement([SystemBackup::STATUS_PENDING, SystemBackup::STATUS_COMPLETED, SystemBackup::STATUS_FAILED]),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SystemBackup::STATUS_PENDING,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SystemBackup::STATUS_COMPLETED,
            'file_size' => fake()->numberBetween(1000000, 500000000),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SystemBackup::STATUS_FAILED,
        ]);
    }
}
