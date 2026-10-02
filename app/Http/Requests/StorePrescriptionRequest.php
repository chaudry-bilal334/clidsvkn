<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePrescriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.medicine_name' => ['required', 'string', 'max:150'],
            'items.*.dosage' => ['nullable', 'string', 'max:100'],
            'items.*.frequency' => ['nullable', 'string', 'max:100'],
            'items.*.duration' => ['nullable', 'string', 'max:100'],
            'items.*.instructions' => ['nullable', 'string'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lineItems(): array
    {
        return collect($this->validated('items', []))->map(fn (array $item) => [
            'medicine_name' => $item['medicine_name'],
            'dosage' => $item['dosage'] ?? null,
            'frequency' => $item['frequency'] ?? null,
            'duration' => $item['duration'] ?? null,
            'instructions' => $item['instructions'] ?? null,
            'quantity' => $item['quantity'] ?? null,
        ])->all();
    }
}
