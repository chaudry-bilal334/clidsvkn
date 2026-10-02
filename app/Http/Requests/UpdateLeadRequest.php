<?php

namespace App\Http\Requests;

use App\Models\Lead;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $lead = $this->route('lead');

        return $lead instanceof Lead && ($this->user()?->can('update', $lead) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The stage cannot be changed through a normal edit; it moves exclusively
     * through the dedicated transition endpoint so every move is audited.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'service_interest' => ['sometimes', 'nullable', 'string', 'max:150'],
            'preferred_doctor_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', 'Doctor')],
            'preferred_date' => ['sometimes', 'nullable', 'date'],
            'preferred_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
