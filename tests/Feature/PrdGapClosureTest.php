<?php

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Diagnosis;
use App\Models\Dispensing;
use App\Models\Encounter;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\QueueEntry;
use App\Models\Refund;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function prd_receptionist(): User
{
    return User::factory()->create(['role' => 'Receptionist']);
}

function prd_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function prd_pharmacist(): User
{
    return User::factory()->create(['role' => 'Pharmacist']);
}

function prd_accountant(): User
{
    return User::factory()->create(['role' => 'Accountant']);
}

function prd_admin(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

/**
 * Fresh re-auth marker stamped into the test session, matching what the
 * reauth endpoint writes for a just-confirmed password/TOTP challenge.
 *
 * @return array<string, int>
 */
function prd_reauth(): array
{
    return ['reauth_verified_at' => now()->timestamp];
}

function prd_scheduled_appointment(User $doctor): Appointment
{
    return Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'status' => 'booked',
    ]);
}

test('a booked appointment is checked into the queue and tracked through the full journey', function () {
    $receptionist = prd_receptionist();
    $doctor = prd_doctor();
    $appointment = prd_scheduled_appointment($doctor);

    $checkedIn = $this->actingAs($receptionist)
        ->postJson('/api/v1/queue', ['appointment_id' => $appointment->id])
        ->assertCreated()
        ->assertJsonPath('data.status', QueueEntry::STATUS_ARRIVED)
        ->assertJsonPath('data.mrn', $appointment->patient->mrn);

    $queueId = $checkedIn->json('data.id');

    expect($appointment->fresh()->status)->toBe('arrived')
        ->and(QueueEntry::find($queueId)->checked_in_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'queue.checked_in')->count())->toBe(1);

    foreach (['waiting', 'in_consultation', 'completed'] as $next) {
        $this->actingAs($receptionist)
            ->putJson("/api/v1/queue/{$queueId}", ['status' => $next])
            ->assertOk()
            ->assertJsonPath('data.status', $next);
    }

    expect(QueueEntry::find($queueId)->status)->toBe('completed')
        ->and($appointment->fresh()->status)->toBe('completed')
        ->and(AuditLog::query()->where('action', 'queue.transitioned')->count())->toBe(3);
});

test('checking in the same appointment twice returns 422', function () {
    $receptionist = prd_receptionist();
    $appointment = prd_scheduled_appointment(prd_doctor());

    $this->actingAs($receptionist)
        ->postJson('/api/v1/queue', ['appointment_id' => $appointment->id])
        ->assertCreated();

    $this->actingAs($receptionist)
        ->postJson('/api/v1/queue', ['appointment_id' => $appointment->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.appointment_id.0', 'This appointment has already been checked in.');

    expect(QueueEntry::count())->toBe(1);
});

test('a queue transition that skips a state is rejected with 422', function () {
    $receptionist = prd_receptionist();
    $appointment = prd_scheduled_appointment(prd_doctor());

    $queueId = $this->actingAs($receptionist)
        ->postJson('/api/v1/queue', ['appointment_id' => $appointment->id])
        ->json('data.id');

    $this->actingAs($receptionist)
        ->putJson("/api/v1/queue/{$queueId}", ['status' => 'completed'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', "Cannot transition the queue entry from 'arrived' to 'completed'.");

    expect(QueueEntry::find($queueId)->status)->toBe('arrived');
});

test('queue entries are listed and filterable by status', function () {
    $receptionist = prd_receptionist();
    $doctor = prd_doctor();
    $first = prd_scheduled_appointment($doctor);
    $second = prd_scheduled_appointment($doctor);

    $this->actingAs($receptionist)->postJson('/api/v1/queue', ['appointment_id' => $first->id])->assertCreated();

    $secondId = $this->actingAs($receptionist)
        ->postJson('/api/v1/queue', ['appointment_id' => $second->id])
        ->json('data.id');
    $this->actingAs($receptionist)->putJson("/api/v1/queue/{$secondId}", ['status' => 'waiting'])->assertOk();

    $this->actingAs($receptionist)
        ->getJson('/api/v1/queue?status=waiting')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $secondId);
});

test('a doctor opens an encounter and records vitals and a diagnosis', function () {
    $doctor = prd_doctor();
    $patient = Patient::factory()->create();

    $encounterId = $this->actingAs($doctor)
        ->postJson('/api/v1/encounters', [
            'patient_id' => $patient->id,
            'notes' => 'Routine follow-up.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.patient_id', $patient->id)
        ->json('data.id');

    $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$encounterId}/vitals", [
            'blood_pressure' => '120/80',
            'temperature' => 36.6,
            'heart_rate' => 72,
            'weight' => 70.5,
        ])
        ->assertCreated()
        ->assertJsonPath('data.blood_pressure', '120/80');

    $this->actingAs($doctor)
        ->postJson("/api/v1/encounters/{$encounterId}/diagnoses", [
            'icd_code' => 'J06.9',
            'description' => 'Acute upper respiratory infection.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.icd_code', 'J06.9');

    $encounter = Encounter::findOrFail($encounterId);
    $vital = Vital::query()->where('encounter_id', $encounterId)->firstOrFail();
    $diagnosis = Diagnosis::query()->where('encounter_id', $encounterId)->firstOrFail();

    expect($vital->recorded_by)->toBe($doctor->id)
        ->and($diagnosis->recorded_by)->toBe($doctor->id)
        ->and($encounter->vitals()->count())->toBe(1)
        ->and($encounter->diagnoses()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'encounter.vitals_recorded')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'encounter.diagnosis_recorded')->count())->toBe(1);

    $this->actingAs($doctor)
        ->getJson("/api/v1/encounters/{$encounterId}")
        ->assertOk()
        ->assertJsonCount(1, 'data.vitals')
        ->assertJsonCount(1, 'data.diagnoses');
});

test('a recorded lab result is pending verification and a second person must verify it', function () {
    $doctor = prd_doctor();
    $labOrder = LabOrder::factory()->create(['status' => 'completed', 'result' => null, 'recorded_by' => null, 'sample_collected_at' => now()]);

    $this->actingAs($doctor)
        ->putJson("/api/v1/lab-orders/{$labOrder->id}", ['result' => 'Hb 13.2 g/dL'])
        ->assertOk()
        ->assertJsonPath('data.recorded_by', $doctor->id)
        ->assertJsonPath('data.verification_state', LabOrder::VERIFICATION_PENDING);

    $pending = $labOrder->fresh();
    $this->actingAs($doctor)->withSession(prd_reauth())
        ->postJson("/api/v1/lab-orders/{$pending->id}/verify")
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.verifier_id.0',
            'The result must be verified by a different user than the one who recorded it.',
        );

    $pharmacist = prd_pharmacist();

    $this->actingAs($pharmacist)->withSession(prd_reauth())
        ->postJson("/api/v1/lab-orders/{$pending->id}/verify")
        ->assertOk()
        ->assertJsonPath('data.verification_state', LabOrder::VERIFICATION_VERIFIED)
        ->assertJsonPath('data.verifier_id', $pharmacist->id);

    $verified = $pending->fresh();

    expect($verified->verified_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'lab.verified')->count())->toBe(1);
});

test('a verified lab result is amended only with a reason and then reverts to pending', function () {
    $doctor = prd_doctor();
    $labOrder = LabOrder::factory()->create([
        'status' => 'completed',
        'result' => 'Glucose 95 mg/dL',
        'recorded_by' => $doctor->id,
        'verification_state' => LabOrder::VERIFICATION_VERIFIED,
        'verifier_id' => prd_pharmacist()->id,
        'verified_at' => now(),
    ]);

    $this->actingAs($doctor)->withSession(prd_reauth())
        ->postJson("/api/v1/lab-orders/{$labOrder->id}/amend", ['result' => 'Glucose 110 mg/dL'])
        ->assertUnprocessable();

    $this->actingAs($doctor)->withSession(prd_reauth())
        ->postJson("/api/v1/lab-orders/{$labOrder->id}/amend", [
            'reason' => 'Repeat sample after re-test.',
            'result' => 'Glucose 110 mg/dL',
        ])
        ->assertOk()
        ->assertJsonPath('data.verification_state', LabOrder::VERIFICATION_AMENDED)
        ->assertJsonPath('data.result', 'Glucose 110 mg/dL')
        ->assertJsonPath('data.verifier_id', null);

    expect($labOrder->fresh()->verifier_id)->toBeNull()
        ->and($labOrder->fresh()->verified_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'lab.amended')->count())->toBe(1);
});

test('dispensing a prescription consumes the earliest-expiring batches first and closes the prescription', function () {
    $pharmacist = prd_pharmacist();
    $prescription = Prescription::factory()->create(['status' => 'pending']);

    $item = InventoryItem::factory()->create(['item_name' => 'Paracetamol 500mg Tablets', 'stock_quantity' => 0, 'reorder_level' => 5]);

    $older = InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-OLDER',
        'expiry_date' => now()->addMonths(6)->toDateString(),
        'initial_quantity' => 10,
        'current_quantity' => 10,
    ]);

    $newer = InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-NEWER',
        'expiry_date' => now()->addMonths(18)->toDateString(),
        'initial_quantity' => 10,
        'current_quantity' => 10,
    ]);

    $this->actingAs($pharmacist)
        ->postJson('/api/v1/dispensings', [
            'prescription_id' => $prescription->id,
            'items' => [
                ['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 15],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.prescription_id', $prescription->id)
        ->assertJsonPath('data.status', 'dispensed')
        ->assertJsonCount(2, 'data.dispensings');

    expect((float) $older->fresh()->current_quantity)->toBe(0.0)
        ->and((float) $newer->fresh()->current_quantity)->toBe(5.0)
        ->and((float) $item->fresh()->stock_quantity)->toBe(5.0)
        ->and((float) $item->fresh()->ledgerStock())->toBe(5.0)
        ->and($prescription->fresh()->status)->toBe('dispensed')
        ->and(Dispensing::count())->toBe(2)
        ->and(InventoryTransaction::query()->where('type', 'dispense')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'dispensing.created')->count())->toBe(1);

    $transactions = InventoryTransaction::query()->where('type', 'dispense')->get();

    expect($transactions->map(fn ($trx) => $trx->inventory_batch_id)->all())->toBe([$older->id, $newer->id]);
});

test('an expired batch is never dispensed', function () {
    $pharmacist = prd_pharmacist();
    $prescription = Prescription::factory()->create(['status' => 'pending']);

    $item = InventoryItem::factory()->create(['item_name' => 'Insulin Syringes', 'stock_quantity' => 0, 'reorder_level' => 1]);

    InventoryBatch::factory()->expired()->create([
        'inventory_item_id' => $item->id,
        'initial_quantity' => 5,
        'current_quantity' => 5,
    ]);

    $this->actingAs($pharmacist)
        ->postJson('/api/v1/dispensings', [
            'prescription_id' => $prescription->id,
            'items' => [
                ['medicine_name' => 'Insulin Syringes', 'quantity' => 1],
            ],
        ])
        ->assertUnprocessable();

    expect(Dispensing::count())->toBe(0)
        ->and(InventoryTransaction::count())->toBe(0)
        ->and($prescription->fresh()->status)->toBe('pending');
});

test('an unfulfillable dispensing leaves no stock movement behind', function () {
    $pharmacist = prd_pharmacist();
    $prescription = Prescription::factory()->create(['status' => 'pending']);

    $item = InventoryItem::factory()->create(['item_name' => 'Saline Solution 500ml', 'stock_quantity' => 0, 'reorder_level' => 1]);

    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'initial_quantity' => 5,
        'current_quantity' => 5,
    ]);

    $this->actingAs($pharmacist)
        ->postJson('/api/v1/dispensings', [
            'prescription_id' => $prescription->id,
            'items' => [
                ['medicine_name' => 'Saline Solution 500ml', 'quantity' => 6],
            ],
        ])
        ->assertUnprocessable();

    expect(Dispensing::count())->toBe(0)
        ->and(InventoryTransaction::count())->toBe(0)
        ->and((float) $item->fresh()->stock_quantity)->toBe(0.0)
        ->and((float) $item->fresh()->ledgerStock())->toBe(5.0);
});

test('a doctor cannot dispense medication', function () {
    $this->actingAs(prd_doctor())
        ->postJson('/api/v1/dispensings', [
            'prescription_id' => 1,
            'items' => [['medicine_name' => 'Paracetamol 500mg Tablets', 'quantity' => 1]],
        ])
        ->assertForbidden();
});

test('a refund is recorded as a reversal and the invoice totals are recomputed', function () {
    $accountant = prd_accountant();
    $invoice = Invoice::factory()->paid()->create(['total' => 1000.00, 'subtotal' => 884.96, 'tax' => 115.04]);

    $payment = Payment::create([
        'invoice_id' => $invoice->id,
        'amount' => 1000.00,
        'method' => 'cash',
        'paid_at' => now(),
    ]);

    $this->actingAs($accountant)->withSession(prd_reauth())
        ->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'amount' => 250.00,
            'payment_id' => $payment->id,
            'reason' => 'Patient overpaid.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.amount', 250)
        ->assertJsonPath('data.payment_id', $payment->id);

    $invoice->refresh();
    $refund = Refund::query()->where('invoice_id', $invoice->id)->firstOrFail();

    expect((float) $invoice->amount_paid)->toBe(750.0)
        ->and((float) $invoice->balance_due)->toBe(250.0)
        ->and($invoice->status)->toBe(Invoice::STATUS_GENERATED)
        ->and($refund->processed_by)->toBe($accountant->id)
        ->and(AuditLog::query()->where('action', 'refund.created')->count())->toBe(1);
});

test('a refund cannot exceed what was paid on the invoice', function () {
    $accountant = prd_accountant();
    $invoice = Invoice::factory()->paid()->create(['total' => 500.00, 'subtotal' => 442.48, 'tax' => 57.52]);

    $this->actingAs($accountant)->withSession(prd_reauth())
        ->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'amount' => 600.00,
            'reason' => 'Reversal.',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.amount.0', 'The refund amount exceeds the refundable amount on this invoice.');

    expect(Refund::count())->toBe(0)
        ->and((float) $invoice->fresh()->amount_paid)->toBe(500.0);
});

test('a refund on a finalized invoice preserves the finalized status', function () {
    $accountant = prd_accountant();
    $invoice = Invoice::factory()->paid()->create([
        'total' => 800.00,
        'subtotal' => 707.96,
        'tax' => 92.04,
        'status' => Invoice::STATUS_FINALIZED,
    ]);

    Payment::create([
        'invoice_id' => $invoice->id,
        'amount' => 800.00,
        'method' => 'cash',
        'paid_at' => now(),
    ]);

    $this->actingAs($accountant)->withSession(prd_reauth())
        ->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'amount' => 100.00,
            'reason' => 'Partial service refund.',
        ])
        ->assertCreated();

    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_FINALIZED)
        ->and((float) $invoice->fresh()->balance_due)->toBe(100.0);
});

test('patient registration mints sequential unique mrns from the sequence', function () {
    $admin = prd_admin();

    $this->actingAs($admin)->postJson('/api/v1/patients', ['name' => 'One', 'email' => 'one@example.com'])->assertJsonPath('data.mrn', 'MRN-000001');
    $this->actingAs($admin)->postJson('/api/v1/patients', ['name' => 'Two', 'email' => 'two@example.com'])->assertJsonPath('data.mrn', 'MRN-000002');
    $this->actingAs($admin)->postJson('/api/v1/patients', ['name' => 'Three', 'email' => 'three@example.com'])->assertJsonPath('data.mrn', 'MRN-000003');

    expect(Patient::pluck('mrn')->all())->toBe(['MRN-000001', 'MRN-000002', 'MRN-000003']);
});

test('queue, encounters, lab verification, dispensing and refunds all require authentication', function () {
    $this->getJson('/api/v1/queue')->assertUnauthorized();
    $this->postJson('/api/v1/encounters')->assertUnauthorized();
    $this->postJson('/api/v1/lab-orders/1/verify')->assertUnauthorized();
    $this->postJson('/api/v1/dispensings')->assertUnauthorized();
    $this->postJson('/api/v1/invoices/1/refunds')->assertUnauthorized();
});
