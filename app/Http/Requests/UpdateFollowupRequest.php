<?php

namespace App\Http\Requests;

use App\Models\Followup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFollowupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $followup = $this->route('followup');

        return $followup instanceof Followup && ($this->user()?->can('update', $followup) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Completion and cancellation use dedicated endpoints so the transitions
     * are audited; this form only adjusts pending scheduling details.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'due_at' => ['sometimes', 'date_format:Y-m-d H:i'],
            'type' => ['sometimes', 'string', Rule::in(Followup::types())],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
