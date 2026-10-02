<?php

namespace Database\Factories;

use App\Models\Claim;
use App\Models\InsuranceProvider;
use App\Models\Invoice;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Claim>
 */
class ClaimFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'invoice_id' => fn (array $attributes) => Invoice::factory()->create(['patient_id' => $attributes['patient_id']])->id,
            'insurance_provider_id' => InsuranceProvider::factory(),
            'policy_number' => 'POL-'.strtoupper(Str::random(10)),
            'claimed_amount' => fn (array $attributes) => Invoice::find($attributes['invoice_id'])?->total,
            'approved_amount' => null,
            'status' => Claim::STATUS_DRAFT,
            'notes' => $this->faker->optional(0.6)->sentence(8),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => Claim::STATUS_SUBMITTED]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => Claim::STATUS_APPROVED,
            'approved_amount' => fn (array $attributes) => $attributes['claimed_amount'],
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => Claim::STATUS_PAID,
            'approved_amount' => fn (array $attributes) => $attributes['claimed_amount'],
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => Claim::STATUS_REJECTED]);
    }
}
