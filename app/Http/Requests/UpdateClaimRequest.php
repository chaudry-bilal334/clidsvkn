<?php

namespace App\Http\Requests;

use App\Models\Claim;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Validation\Rule;

class UpdateClaimRequest extends StoreClaimRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $claim = $this->route('claim');

        return $claim instanceof Claim
            && ($this->user()?->can('update', $claim) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'insurance_provider_id' => [
                'sometimes',
                'integer',
                Rule::exists('insurance_providers', 'id')->where('is_active', true),
            ],
            'policy_number' => ['sometimes', 'string', 'max:60'],
            'claimed_amount' => ['sometimes', 'numeric', 'min:0.01', 'max:9999999.99'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Reject edits that would push the claim beyond its invoice balance.
     */
    public function after(): array
    {
        $claim = $this->route('claim');

        if (! $claim instanceof Claim) {
            return [];
        }

        $invoice = $claim->invoice;

        if ($invoice === null) {
            return [];
        }

        if (! $this->filled('claimed_amount')) {
            return [];
        }

        return [
            function (ValidatorContract $validator) use ($invoice): void {
                $this->assertClaimWithinBalance($validator, (float) $this->input('claimed_amount'), $invoice);
            },
        ];
    }
}
