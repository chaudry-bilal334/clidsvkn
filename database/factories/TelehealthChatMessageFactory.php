<?php

namespace Database\Factories;

use App\Models\TelehealthChatMessage;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelehealthChatMessage>
 */
class TelehealthChatMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => TelehealthSession::factory(),
            'sender_id' => User::factory(),
            'sender_name' => $this->faker->name(),
            'body' => $this->faker->sentence(),
        ];
    }
}
