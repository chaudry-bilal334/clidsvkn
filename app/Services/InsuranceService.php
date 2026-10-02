<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\InsuranceProvider;
use App\Models\Invoice;

class InsuranceService
{
    /**
     * The claim lifecycle states, ordered by financial commitment.
     *
     * @return array<int, string>
     */
    public function statuses(): array
    {
        return [
            Claim::STATUS_DRAFT,
            Claim::STATUS_SUBMITTED,
            Claim::STATUS_APPROVED,
            Claim::STATUS_REJECTED,
            Claim::STATUS_PAID,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function transitions(): array
    {
        return [
            Claim::STATUS_DRAFT => [Claim::STATUS_SUBMITTED],
            Claim::STATUS_SUBMITTED => [Claim::STATUS_APPROVED, Claim::STATUS_REJECTED],
            Claim::STATUS_APPROVED => [Claim::STATUS_PAID],
            Claim::STATUS_REJECTED => [Claim::STATUS_DRAFT],
            Claim::STATUS_PAID => [],
        ];
    }

    /**
     * The statuses a claim may move into from its current one.
     *
     * @return array<int, string>
     */
    public function availableTransitions(Claim $claim): array
    {
        return $this->transitions()[$claim->status] ?? [];
    }

    /**
     * Abort the request when the requested transition is not permitted.
     */
    public function assertTransitionAllowed(Claim $claim, string $nextStatus): void
    {
        if (! in_array($nextStatus, $this->availableTransitions($claim), true)) {
            abort(422, "A {$claim->status} claim cannot be moved to {$nextStatus}.");
        }
    }

    /**
     * Confirm the claim uses an active carrier before it leaves the draft state.
     */
    public function assertProviderActive(Claim $claim): void
    {
        if (! $claim->insuranceProvider?->is_active) {
            abort(422, 'The claim cannot be submitted under an inactive insurance provider.');
        }
    }

    /**
     * Guarantee the requested amount never exceeds what the invoice still owes.
     *
     * The receivable against an invoice is its outstanding balance; claiming
     * more than the balance (or against a fully settled invoice) is rejected.
     */
    public function assertAmountWithinInvoice(float $amount, Invoice $invoice, ?int $ignoreClaimId = null): void
    {
        $balance = (float) $invoice->balance_due;

        if ($amount > $balance) {
            abort(422, 'The claim amount exceeds the outstanding balance ('.number_format($balance, 2).') of this invoice.');
        }

        $alreadyApproved = Claim::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', Claim::STATUS_APPROVED)
            ->when($ignoreClaimId, fn ($query) => $query->where('id', '!=', $ignoreClaimId))
            ->sum('approved_amount');

        if (round($alreadyApproved + $amount, 2) > $balance) {
            abort(422, 'The combined approved amounts exceed the outstanding balance of this invoice.');
        }
    }

    /**
     * Orchestrate a state change, applying the business rules for each step.
     */
    public function transition(Claim $claim, string $nextStatus, ?float $approvedAmount = null, ?string $notes = null): Claim
    {
        $this->assertTransitionAllowed($claim, $nextStatus);

        switch ($nextStatus) {
            case Claim::STATUS_SUBMITTED:
                $this->assertProviderActive($claim);

                break;

            case Claim::STATUS_APPROVED:
                $amount = $approvedAmount ?? (float) $claim->claimed_amount;

                if ($amount > (float) $claim->claimed_amount) {
                    abort(422, 'The approved amount cannot exceed the claimed amount.');
                }

                $this->assertAmountWithinInvoice($amount, $claim->invoice, $claim->id);
                $claim->approved_amount = $amount;

                break;

            case Claim::STATUS_REJECTED:
                $claim->notes = $notes ?? $claim->notes;

                break;

            case Claim::STATUS_PAID:
                if ($claim->approved_amount === null) {
                    abort(422, 'Only approved claims can be settled as paid.');
                }

                $this->settleInvoice($claim);

                break;

            case Claim::STATUS_DRAFT:
                $claim->approved_amount = null;

                break;
        }

        $claim->status = $nextStatus;
        $claim->save();

        return $claim;
    }

    /**
     * Reconcile the settled claim against its invoice, forwarding the approved
     * amount to the patient's outstanding balance.
     */
    private function settleInvoice(Claim $claim): void
    {
        $invoice = $claim->invoice;
        $amount = (float) $claim->approved_amount;

        $this->assertAmountWithinInvoice($amount, $invoice, $claim->id);

        $invoice->amount_paid = round((float) $invoice->amount_paid + $amount, 2);
        $invoice->balance_due = round((float) $invoice->total - $invoice->amount_paid, 2);
        $invoice->status = $invoice->balance_due > 0 ? $invoice->status : 'paid';
        $invoice->save();
    }

    /**
     * Ensure a provider is valid for a new claim (needed during creation).
     */
    public function assertProviderExists(InsuranceProvider $provider): void
    {
        if (! $provider->is_active) {
            abort(422, 'Claims can only be filed with active insurance providers.');
        }
    }
}
