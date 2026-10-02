<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expense_category_id' => ['sometimes', 'required', 'integer', 'exists:expense_categories,id'],
            'expense_date' => ['sometimes', 'required', 'date'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'payment_status' => ['sometimes', 'required', 'string', Rule::in(['pending', 'paid', 'partial'])],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
