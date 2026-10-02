<?php

namespace App\Http\Requests;

use App\Models\Followup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFollowupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Followup::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'encounter_id' => ['nullable', 'integer', 'exists:encounters,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['required', 'date_format:Y-m-d H:i'],
            'type' => ['nullable', 'string', Rule::in(Followup::types())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * A follow-up must point at a patient and/or a lead so the task is traceable.
     */
    public function after(): array
    {
        return [
            function (ValidatorContract $validator): void {
                if (! $this->filled('patient_id') && ! $this->filled('lead_id')) {
                    $validator->errors()->add('lead_id', 'A follow-up must be linked to a patient and/or a lead.');
                }
            },
        ];
    }
}
