<?php

namespace App\Http\Requests;

use App\Models\Lead;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionLeadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $lead = $this->route('lead');

        return $lead instanceof Lead && ($this->user()?->can('transition', $lead) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The legal moves themselves are enforced by LeadService so an illegal
     * stage change is rejected even if it passes basic validation here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(Lead::statuses())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
