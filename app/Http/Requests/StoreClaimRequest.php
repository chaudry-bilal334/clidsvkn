<?php

namespace App\Http\Requests;

use App\Models\Claim;
use App\Models\Invoice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClaimRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Claim::class) ?? false;
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
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'insurance_provider_id' => [
                'required',
                'integer',
                Rule::exists('insurance_providers', 'id')->where('is_active', true),
            ],
            'policy_number' => ['required', 'string', 'max:60'],
            'claimed_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Reject claims that exceed the invoice's outstanding balance.
     */
    public function after(): array
    {
        return [
            function (ValidatorContract $validator): void {
                $invoice = Invoice::find($this->integer('invoice_id'));

                if ($invoice !== null) {
                    $this->assertClaimWithinBalance($validator, (float) $this->input('claimed_amount', 0), $invoice);
                }
            },
        ];
    }

    /**
     * Add a validation error when the claimed amount exceeds the balance due.
     */
    protected function assertClaimWithinBalance(
        ValidatorContract $validator,
        float $amount,
        Invoice $invoice,
    ): void {
        if ($amount > (float) $invoice->balance_due) {
            $validator->errors()->add(
                'claimed_amount',
                'The claim amount exceeds the outstanding balance ('.number_format((float) $invoice->balance_due, 2).') of this invoice.',
            );
        }
    }
}
