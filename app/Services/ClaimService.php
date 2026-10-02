<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClaimService
{
    /**
     * Create a new claim in the initial `draft` state.
     *
     * @param  array{
     *     patient_id: int,
     *     invoice_id: int,
     *     insurance_provider_id: int,
     *     policy_number: string,
     *     claimed_amount: float,
     *     notes: string|null
     * }  $fields
     */
    public function create(array $fields, User $actor): Claim
    {
        return DB::transaction(function () use ($fields, $actor): Claim {
            $invoice = Invoice::findOrFail($fields['invoice_id']);

            // A claim must settle an invoice that belongs to the same patient;
            // otherwise the claim would be filed against someone else's bill.
            if ((int) $invoice->patient_id !== (int) $fields['patient_id']) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['The invoice does not belong to this patient.'],
                ]);
            }

            $claim = Claim::create([
                'patient_id' => $fields['patient_id'],
                'invoice_id' => $fields['invoice_id'],
                'insurance_provider_id' => $fields['insurance_provider_id'],
                'policy_number' => $fields['policy_number'],
                'claimed_amount' => $fields['claimed_amount'],
                'status' => Claim::STATUS_DRAFT,
                'notes' => $fields['notes'] ?? null,
            ]);

            AuditLog::record(
                $actor,
                'claim.created',
                $claim,
                newValues: [
                    'patient_id' => $fields['patient_id'],
                    'invoice_id' => $fields['invoice_id'],
                    'insurance_provider_id' => $fields['insurance_provider_id'],
                    'policy_number' => $fields['policy_number'],
                    'claimed_amount' => $fields['claimed_amount'],
                ],
            );

            return $claim;
        });
    }
}
