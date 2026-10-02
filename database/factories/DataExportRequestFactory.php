<?php

namespace Database\Factories;

use App\Models\DataExportRequest;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataExportRequest>
 */
class DataExportRequestFactory extends Factory
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
            'requested_by' => User::factory(),
            'status' => DataExportRequest::STATUS_PENDING,
            'file_path' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => DataExportRequest::STATUS_COMPLETED,
            'file_path' => 'exports/patient-'.$this->faker->uuid().'.json',
        ]);
    }
}
