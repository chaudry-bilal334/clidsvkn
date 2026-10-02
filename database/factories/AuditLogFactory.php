<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => 'resource.created',
            'auditable_type' => Patient::class,
            'auditable_id' => Patient::factory(),
            'old_values' => null,
            'new_values' => ['name' => $this->faker->name()],
            'ip_address' => $this->faker->ipv4(),
        ];
    }
}
