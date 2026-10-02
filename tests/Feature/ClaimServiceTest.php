<?php

use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\InsuranceProvider;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

pest()->use(RefreshDatabase::class);

function clm_staff(): User
{
    return User::factory()->create(['role' => 'Billing']);
}

/**
 * @return array{patient: Patient, invoice: Invoice, provider: InsuranceProvider}
 */
function clm_context(?User $staff = null): array
{
    $staff ??= clm_staff();

    $patient = Patient::factory()->create(['branch_id' => $staff->branch_id]);

    return [
        'patient' => $patient,
        'invoice' => Invoice::factory()->create([
            'patient_id' => $patient->id,
            'branch_id' => $staff->branch_id,
        ]),
        'provider' => InsuranceProvider::factory()->create(),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function clm_fields(Patient $patient, Invoice $invoice, InsuranceProvider $provider, array $overrides = []): array
{
    return array_merge([
        'patient_id' => $patient->id,
        'invoice_id' => $invoice->id,
        'insurance_provider_id' => $provider->id,
        'policy_number' => 'POL-0001',
        'claimed_amount' => 2500.00,
    ], $overrides);
}

test('a claim is created in the draft state', function () {
    $staff = clm_staff();
    ['patient' => $patient, 'invoice' => $invoice, 'provider' => $provider] = clm_context($staff);

    $claim = app(ClaimService::class)->create(
        clm_fields($patient, $invoice, $provider),
        $staff,
    );

    expect($claim->status)->toBe(Claim::STATUS_DRAFT)
        ->and($claim->patient_id)->toBe($patient->id)
        ->and($claim->invoice_id)->toBe($invoice->id)
        ->and($claim->policy_number)->toBe('POL-0001')
        ->and((float) $claim->claimed_amount)->toBe(2500.00)
        ->and($claim->approved_amount)->toBeNull();
});

test('a client supplied status cannot override the draft state', function () {
    $staff = clm_staff();
    ['patient' => $patient, 'invoice' => $invoice, 'provider' => $provider] = clm_context($staff);

    $claim = app(ClaimService::class)->create(
        clm_fields($patient, $invoice, $provider, [
            'status' => Claim::STATUS_APPROVED,
            'approved_amount' => 9999.00,
        ]),
        $staff,
    );

    expect($claim->status)->toBe(Claim::STATUS_DRAFT)
        ->and($claim->approved_amount)->toBeNull();
});

test('claim creation is recorded in the audit trail', function () {
    $staff = clm_staff();
    ['patient' => $patient, 'invoice' => $invoice, 'provider' => $provider] = clm_context($staff);

    $claim = app(ClaimService::class)->create(
        clm_fields($patient, $invoice, $provider),
        $staff,
    );

    $audit = AuditLog::query()
        ->where('action', 'claim.created')
        ->where('auditable_id', $claim->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($staff->id)
        ->and($audit->auditable_type)->toBe(Claim::class)
        ->and($audit->new_values['invoice_id'])->toBe($invoice->id)
        ->and($audit->new_values['policy_number'])->toBe('POL-0001');
});

test('a claim is rolled back when the invoice does not belong to the patient', function () {
    $staff = clm_staff();
    ['invoice' => $invoice, 'provider' => $provider] = clm_context($staff);
    $otherPatient = Patient::factory()->create(['branch_id' => $staff->branch_id]);

    // The service refuses to file a claim against another patient's invoice,
    // and nothing is persisted when it does.
    expect(fn () => app(ClaimService::class)->create(
        clm_fields($otherPatient, $invoice, $provider),
        $staff,
    ))->toThrow(ValidationException::class, 'The invoice does not belong to this patient.');

    expect(Claim::count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'claim.created')->count())->toBe(0);
});

test('notes are optional on a claim', function () {
    $staff = clm_staff();
    ['patient' => $patient, 'invoice' => $invoice, 'provider' => $provider] = clm_context($staff);

    $claim = app(ClaimService::class)->create(
        clm_fields($patient, $invoice, $provider),
        $staff,
    );

    expect($claim->notes)->toBeNull();
});
