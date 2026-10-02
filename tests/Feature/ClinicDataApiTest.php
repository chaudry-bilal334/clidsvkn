<?php

use App\Events\AppointmentStatusChanged;
use App\Events\LowStockAlertTriggered;
use App\Models\Appointment;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

pest()->use(RefreshDatabase::class);

function adminUser(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

test('patients can be created, listed, searched and deleted', function () {
    $user = adminUser();

    Patient::factory()->create(['name' => 'Existing Patient', 'email' => 'existing@example.com']);

    $this->actingAs($user)
        ->postJson('/api/v1/patients', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+1 (555) 200-0001',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Jane Doe');

    $this->actingAs($user)
        ->getJson('/api/v1/patients')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($user)
        ->getJson('/api/v1/patients?search=jane')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Jane Doe');

    $jane = Patient::where('email', 'jane@example.com')->firstOrFail();

    $this->actingAs($user)
        ->deleteJson("/api/v1/patients/{$jane->id}")
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/patients')
        ->assertJsonCount(1, 'data');
});

test('a single patient can be fetched by id for the clinical profile', function () {
    $user = adminUser();
    $patient = Patient::factory()->create(['name' => 'Profile Patient', 'mrn' => 'MRN-10001']);

    $this->actingAs($user)
        ->getJson("/api/v1/patients/{$patient->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $patient->id)
        ->assertJsonPath('data.mrn', 'MRN-10001')
        ->assertJsonPath('data.name', 'Profile Patient');
});

test('a doctor can create a pending lab order for a patient', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $patient = Patient::factory()->create();

    $this->actingAs($doctor)
        ->postJson('/api/v1/lab-orders', [
            'patient_id' => $patient->id,
            'test_name' => 'Lipid Profile',
        ])
        ->assertCreated()
        ->assertJsonPath('data.patient_id', $patient->id)
        ->assertJsonPath('data.test_name', 'Lipid Profile')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.verification_state', 'pending_verification');

    $this->actingAs($doctor)
        ->getJson("/api/v1/lab-orders?patient_id={$patient->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.patient_name', $patient->name)
        ->assertJsonPath('data.0.mrn', $patient->mrn);
});

test('appointments can be listed and their status moved along the state machine with a broadcast', function () {
    Event::fake([AppointmentStatusChanged::class]);

    $user = User::factory()->create(['role' => 'Doctor']);
    $appointment = Appointment::factory()->create(['status' => 'booked']);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'booked')
        ->assertJsonPath('data.0.allowed_transitions', ['confirmed', 'arrived', 'cancelled', 'no_show']);

    // PRD §6 forbids skipping the lifecycle, so booked -> completed is rejected.
    $this->actingAs($user)
        ->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => 'completed'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'Cannot move an appointment from booked to completed.');

    foreach (['confirmed', 'arrived', 'waiting', 'in_consultation', 'completed'] as $next) {
        $this->actingAs($user)
            ->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => $next])
            ->assertOk()
            ->assertJsonPath('data.status', $next);
    }

    Event::assertDispatched(AppointmentStatusChanged::class, static function (AppointmentStatusChanged $event) use ($appointment): bool {
        return $event->status === 'completed'
            && $event->appointment['id'] === $appointment->id;
    });
});

test('inventory purchases increase stock, dispenses respect stock and alert on low stock', function () {
    Event::fake([LowStockAlertTriggered::class]);

    $user = adminUser();
    $item = InventoryItem::factory()->create([
        'sku' => 'TEST-MED-0001',
        'stock_quantity' => 0,
        'reorder_level' => 20,
        'unit_price' => 5.00,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory/transactions', [
            'item_id' => $item->id,
            'type' => 'purchase',
            'quantity' => 50,
            'reason' => 'restock',
        ])
        ->assertCreated()
        ->assertJsonPath('data.item_name', $item->item_name);

    $this->actingAs($user)
        ->getJson('/api/v1/inventory')
        ->assertOk()
        ->assertJsonPath('data.0.stock_quantity', 50);

    Event::assertNotDispatched(LowStockAlertTriggered::class);

    $lowItem = InventoryItem::factory()->create([
        'sku' => 'TEST-MED-0002',
        'stock_quantity' => 10,
        'reorder_level' => 20,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/inventory/transactions', [
            'item_id' => $lowItem->id,
            'type' => 'dispense',
            'quantity' => 1,
        ])
        ->assertCreated();

    Event::assertDispatched(LowStockAlertTriggered::class, static function (LowStockAlertTriggered $event) use ($lowItem): bool {
        return $event->item['id'] === $lowItem->id
            && $event->currentStock === 9
            && $event->reorderLevel === 20;
    });

    $this->actingAs($user)
        ->postJson('/api/v1/inventory/transactions', [
            'item_id' => $item->id,
            'type' => 'dispense',
            'quantity' => 500,
        ])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->getJson('/api/v1/inventory/transactions')
        ->assertOk()
        ->assertJsonStructure(['data' => [['item_name', 'type', 'quantity', 'created_at']]]);
});

test('invoices can be created, paid, and overpaid amounts are rejected', function () {
    $user = adminUser();
    $patient = Patient::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'patient_name' => $patient->name,
            'subtotal' => 100.00,
            'tax' => 13.00,
        ])
        ->assertCreated()
        ->assertJsonPath('data.invoice_number', 'INV-00001')
        // `total` is derived from subtotal + tax on the server.
        ->assertJsonPath('data.total', 113)
        ->assertJsonPath('data.balance_due', 113)
        ->assertJsonPath('data.amount_paid', 0)
        ->assertJsonPath('data.status', 'generated');

    $invoice = Invoice::firstOrFail();

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => 113.00])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    expect($invoice->refresh()->balance_due)->toBe('0.00')
        ->and($invoice->amount_paid)->toBe('113.00');

    $this->actingAs($user)
        ->getJson('/api/v1/invoices')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('prescriptions can be created, listed per patient, and updated alongside lab orders', function () {
    $user = User::factory()->create(['role' => 'Doctor']);
    $patient = Patient::factory()->create();
    $other = Patient::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/prescriptions', [
            'patient_id' => $patient->id,
            'status' => 'pending',
            'notes' => 'Recheck in two weeks.',
            'items' => [
                ['medicine_name' => 'Amoxicillin 500mg', 'dosage' => '500 mg', 'frequency' => 'three times daily', 'duration' => '7 days', 'quantity' => 21],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.prescription_number', 'RX-00001')
        ->assertJsonCount(1, 'data.items');

    $this->actingAs($user)
        ->getJson("/api/v1/prescriptions?patient_id={$patient->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($user)
        ->getJson("/api/v1/prescriptions?patient_id={$other->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $labOrder = LabOrder::factory()->create(['patient_id' => $patient->id, 'status' => 'pending']);

    $this->actingAs($user)
        ->getJson("/api/v1/lab-orders?patient_id={$patient->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($user)
        ->postJson("/api/v1/lab-orders/{$labOrder->id}/collect-sample")
        ->assertOk();

    $this->actingAs($user)
        ->putJson("/api/v1/lab-orders/{$labOrder->id}", ['status' => 'completed', 'result' => 'Within normal limits.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');
});

test('report endpoints return the expected shapes', function () {
    $user = adminUser();

    Appointment::factory()->create(['status' => 'completed', 'scheduled_at' => now()->subDay()->format('Y-m-d H:i:s')]);
    Invoice::factory()->create();
    InventoryItem::factory()->lowStock()->create();
    LabOrder::factory()->create(['test_name' => 'Complete Blood Count']);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/financial')
        ->assertOk()
        ->assertJsonStructure(['data' => [['label', 'total', 'period']]]);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/visits')
        ->assertOk()
        ->assertJsonStructure(['data' => [['date', 'count']]]);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/lab-tests')
        ->assertOk()
        ->assertJsonStructure(['data' => [['name', 'count']]]);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/low-stock')
        ->assertOk()
        ->assertJsonStructure(['data' => [['item_sku', 'item_name', 'current_stock', 'reorder_level', 'shortage']]]);
});
