<?php

use App\Models\Allergen;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\DosageRule;
use App\Models\DrugInteractionRule;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

test('database unique constraint rejects double booking of a doctor slot', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $slot = now()->addDays(1)->startOfHour()->format('Y-m-d H:i:s');

    Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => Patient::factory()->create()->id,
        'scheduled_at' => $slot,
        'status' => Appointment::STATUS_BOOKED,
    ]);

    expect(fn () => Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => Patient::factory()->create()->id,
        'scheduled_at' => $slot,
        'status' => Appointment::STATUS_BOOKED,
    ]))->toThrow(QueryException::class);

    expect(Appointment::count())->toBe(1);
});

test('database unique constraint rejects duplicate patient mrn', function () {
    Patient::factory()->create(['mrn' => 'MRN-000001']);

    expect(fn () => Patient::factory()->create(['mrn' => 'MRN-000001']))
        ->toThrow(QueryException::class);

    expect(Patient::count())->toBe(1);
});

test('patient creation assigns a unique mrn and encrypts cnic', function () {
    $this->actingAs(adminUser())
        ->postJson('/api/v1/patients', [
            'name' => 'Ayesha Khan',
            'email' => 'ayesha@example.com',
            'phone' => '+92 300 1234567',
            'cnic' => '61101-1234567-1',
        ])
        ->assertCreated()
        ->assertJsonPath('data.mrn', 'MRN-000001')
        ->assertJsonMissingPath('data.cnic');

    $patient = Patient::query()->firstOrFail();

    expect($patient->mrn)->toBe('MRN-000001')
        ->and($patient->cnic)->toBe('61101-1234567-1')
        ->and(DB::table('patients')->first()->cnic)->not->toBe('61101-1234567-1');
});

test('prescription with known drug interaction is blocked without an override reason', function () {
    $doctor = ic_doctor();
    $patient = Patient::factory()->create();

    DrugInteractionRule::factory()->between('Warfarin', 'Ibuprofen')->approved()->create();

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'status' => 'pending',
            'items' => [
                ['medicine_name' => 'Warfarin 5mg', 'dosage' => '5 mg', 'frequency' => 'once daily', 'duration' => '30 days', 'quantity' => 30],
                ['medicine_name' => 'Ibuprofen 400mg', 'dosage' => '400 mg', 'frequency' => 'three times daily', 'duration' => '10 days', 'quantity' => 30],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.safety_alerts.0.trigger_type', 'drug_interaction')
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'override_reason'));

    expect(Prescription::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

test('prescription over the maximum daily dose is blocked without an override reason', function () {
    $doctor = ic_doctor();
    $patient = Patient::factory()->create();

    DosageRule::factory()->forMedicine('Ibuprofen', 2400)->approved()->create();

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'status' => 'pending',
            'items' => [
                ['medicine_name' => 'Ibuprofen 200mg', 'dosage' => '800 mg', 'frequency' => 'four times daily', 'duration' => '10 days', 'quantity' => 80],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.safety_alerts.0.trigger_type', 'dosage_check');

    expect(Prescription::count())->toBe(0);
});

test('prescription matching an allergy history is blocked without an override reason', function () {
    $doctor = ic_doctor();
    $patient = Patient::factory()->create();

    $allergen = Allergen::factory()->approved()->create([
        'name' => 'Penicillin',
        'family' => 'penicillin',
        'aliases' => ['amoxicillin', 'ampicillin', 'penicillin'],
    ]);

    PatientMedicalHistory::factory()->allergyTo($allergen)->create([
        'patient_id' => $patient->id,
    ]);

    ic_prescriptionWithItems($patient, $doctor, [
        ['medicine_name' => 'Amoxicillin 500mg', 'dosage' => '500 mg', 'frequency' => 'three times daily', 'duration' => '7 days', 'quantity' => 21],
    ], 'dispensed');

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'status' => 'pending',
            'items' => [
                ['medicine_name' => 'Amoxicillin 250mg', 'dosage' => '250 mg', 'frequency' => 'three times daily', 'duration' => '7 days', 'quantity' => 21],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.safety_alerts.0.trigger_type', 'allergy_warning');

    expect(Prescription::count())->toBe(1);
});

test('override reason allows a flagged prescription and logs the override', function () {
    $doctor = ic_doctor();
    $patient = Patient::factory()->create();

    DrugInteractionRule::factory()->between('Warfarin', 'Ibuprofen')->create();

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'status' => 'pending',
            'override_reason' => 'No alternative available; monitor INR weekly.',
            'items' => [
                ['medicine_name' => 'Warfarin 5mg', 'dosage' => '5 mg', 'frequency' => 'once daily', 'duration' => '30 days', 'quantity' => 30],
                ['medicine_name' => 'Ibuprofen 400mg', 'dosage' => '400 mg', 'frequency' => 'three times daily', 'duration' => '10 days', 'quantity' => 30],
            ],
        ])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'prescription_number']]);

    expect(DB::table('audit_logs')->where('action', 'prescription.created')->count())->toBe(1);

    $override = AuditLog::query()->where('action', 'prescription.override')->firstOrFail();

    expect($override->new_values['override_reason'])->toBe('No alternative available; monitor INR weekly.')
        ->and($override->new_values['safety_alerts'][0]['trigger_type'])->toBe('drug_interaction');
});

test('valid prescription passes safety checks and records the creation audit', function () {
    $doctor = ic_doctor();
    $patient = Patient::factory()->create();

    $this->actingAs($doctor)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'status' => 'pending',
            'items' => [
                ['medicine_name' => 'Amoxicillin 500mg', 'dosage' => '500 mg', 'frequency' => 'three times daily', 'duration' => '7 days', 'quantity' => 21],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.prescription_number', 'RX-00001')
        ->assertJsonCount(1, 'data.items');

    expect(DB::table('audit_logs')->where('action', 'prescription.created')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'prescription.override')->count())->toBe(0);
});

test('audit log records the actor, morph target, and value snapshots', function () {
    $user = adminUser();
    $prescription = Prescription::factory()->create(['doctor_id' => $user->id]);

    AuditLog::record($user, 'prescription.created', $prescription, null, ['items' => ['Amoxicillin 500mg']], '127.0.0.1');

    $log = AuditLog::query()->with('auditable')->firstOrFail();

    expect($log->user_id)->toBe($user->id)
        ->and($log->action)->toBe('prescription.created')
        ->and($log->auditable->is($prescription))->toBeTrue()
        ->and($log->new_values)->toBe(['items' => ['Amoxicillin 500mg']])
        ->and($log->ip_address)->toBe('127.0.0.1');
});

test('deleting an actor preserves their audit logs and nulls the actor', function () {
    // PRD §15/§17: an audit trail must outlive the account that produced it.
    // The FK is SET NULL, so the row survives and stays attributable to the
    // action even after the user record is gone.
    $user = adminUser();
    $prescription = Prescription::factory()->create(['doctor_id' => $user->id]);

    AuditLog::record($user, 'prescription.created', $prescription);

    expect(AuditLog::count())->toBe(1);

    $user->delete();

    expect(AuditLog::count())->toBe(1)
        ->and(AuditLog::first()->user_id)->toBeNull()
        ->and(AuditLog::first()->action)->toBe('prescription.created');
});

test('database unique constraint rejects a second active booking but allows rebooking a released slot', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $patient = Patient::factory()->create();
    $slot = now()->addDays(1)->startOfHour()->format('Y-m-d H:i:s');

    $appointment = Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'scheduled_at' => $slot,
        'status' => Appointment::STATUS_BOOKED,
    ]);

    expect(fn () => Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => Patient::factory()->create()->id,
        'scheduled_at' => $slot,
        'status' => Appointment::STATUS_BOOKED,
    ]))->toThrow(QueryException::class);

    expect(Appointment::count())->toBe(1);

    // Releasing the slot (PRD §6) must make it bookable again — the old
    // unconditional UNIQUE(doctor_id, scheduled_at) permanently burned it.
    $appointment->update(['status' => Appointment::STATUS_CANCELLED]);

    $rebooked = Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => Patient::factory()->create()->id,
        'scheduled_at' => $slot,
        'status' => Appointment::STATUS_BOOKED,
    ]);

    expect($rebooked->exists)->toBeTrue()
        ->and(Appointment::count())->toBe(2);
});

test('dispensing consumes earliest expiring batches first and syncs stock from the ledger', function () {
    $item = InventoryItem::factory()->create(['stock_quantity' => 0, 'reorder_level' => 5]);

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

    $this->actingAs(adminUser())
        ->postJson('/api/v1/inventory/transactions', [
            'item_id' => $item->id,
            'type' => 'dispense',
            'quantity' => 15,
        ])
        ->assertCreated();

    expect((float) $older->fresh()->current_quantity)->toBe(0.0)
        ->and((float) $newer->fresh()->current_quantity)->toBe(5.0);

    $transaction = InventoryTransaction::query()->where('type', 'dispense')->firstOrFail();

    expect($transaction->inventory_batch_id)->toBe($newer->id)
        ->and((float) $item->fresh()->stock_quantity)->toBe(5.0);
});

test('expired batches are excluded from dispensing', function () {
    $item = InventoryItem::factory()->create(['stock_quantity' => 0, 'reorder_level' => 5]);

    InventoryBatch::factory()->expired()->create([
        'inventory_item_id' => $item->id,
        'initial_quantity' => 10,
        'current_quantity' => 10,
    ]);

    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'initial_quantity' => 5,
        'current_quantity' => 5,
    ]);

    $this->actingAs(adminUser())
        ->postJson('/api/v1/inventory/transactions', [
            'item_id' => $item->id,
            'type' => 'dispense',
            'quantity' => 6,
        ])
        ->assertUnprocessable();

    $this->actingAs(adminUser())
        ->postJson('/api/v1/inventory/transactions', [
            'item_id' => $item->id,
            'type' => 'dispense',
            'quantity' => 4,
        ])
        ->assertCreated();
});

test('a dispense beyond available stock leaves the ledger and stock unchanged', function () {
    $item = InventoryItem::factory()->create(['stock_quantity' => 0, 'reorder_level' => 1]);

    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'initial_quantity' => 5,
        'current_quantity' => 5,
    ]);

    $this->actingAs(adminUser())
        ->postJson('/api/v1/inventory/transactions', [
            'item_id' => $item->id,
            'type' => 'dispense',
            'quantity' => 6,
        ])
        ->assertUnprocessable();

    expect(InventoryTransaction::count())->toBe(0)
        ->and((float) $item->fresh()->stock_quantity)->toBe(0.0)
        ->and((float) $item->fresh()->ledgerStock())->toBe(5.0);
});

test('stock cannot drop below zero across row-locked dispense transactions', function () {
    $user = adminUser();
    $item = InventoryItem::factory()->create(['stock_quantity' => 0, 'reorder_level' => 1]);

    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'initial_quantity' => 5,
        'current_quantity' => 5,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory/transactions', [
            'item_id' => $item->id,
            'type' => 'dispense',
            'quantity' => 3,
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/inventory/transactions', [
            'item_id' => $item->id,
            'type' => 'dispense',
            'quantity' => 3,
        ])
        ->assertUnprocessable();

    expect((float) $item->fresh()->stock_quantity)->toBe(2.0)
        ->and((float) $item->fresh()->ledgerStock())->toBe(2.0)
        ->and(InventoryTransaction::query()->where('type', 'dispense')->count())->toBe(1);
});

test('inventory mutation is atomic when a later step fails', function () {
    $item = InventoryItem::factory()->create(['stock_quantity' => 5, 'reorder_level' => 1]);

    try {
        DB::transaction(function () use ($item): void {
            $this->actingAs(adminUser())
                ->postJson('/api/v1/inventory/transactions', [
                    'item_id' => $item->id,
                    'type' => 'dispense',
                    'quantity' => 5,
                ])
                ->assertCreated();

            throw new RuntimeException('simulated outage after the dispense');
        });
    } catch (RuntimeException) {
    }

    expect(InventoryTransaction::count())->toBe(0)
        ->and((float) $item->fresh()->stock_quantity)->toBe(5.0)
        ->and((float) $item->fresh()->ledgerStock())->toBe(5.0);
});
