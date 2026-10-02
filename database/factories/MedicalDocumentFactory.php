<?php

namespace Database\Factories;

use App\Models\MedicalDocument;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalDocument>
 */
class MedicalDocumentFactory extends Factory
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
            'uploaded_by' => null,
            'category' => $this->faker->randomElement(MedicalDocument::CATEGORIES),
            'original_name' => $this->faker->word().'.pdf',
            'file_path' => 'documents/'.$this->faker->lexify('????????????????????').'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(4_096, 2_000_000),
        ];
    }
}
