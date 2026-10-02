<?php

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Encounter;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\LabOrder;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\QueueEntry;
use App\Models\StockAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

pest()->use(RefreshDatabase::class);

function ops_user(string $role = 'Admin'): User
{
    return User::factory()->create(['role' => $role]);
}

function ops_range(): array
{
    return [now()->subDays(29)->toDateString(), now()->toDateString()];
}

/**
 * An inventory item with no factory-made ledger batch.
 *
 * `InventoryItemFactory::configure()` mirrors `stock_quantity` into a matching
 * batch on create, so an item created with zero stock produces no extra batch
 * and the expiry report contains exactly the batches a test declares.
 */
function ops_item(string $name, float $price): InventoryItem
{
    return InventoryItem::factory()->create([
        'item_name' => $name,
        'unit_price' => $price,
        'stock_quantity' => 0,
    ]);
}

/**
 * A lab order with an explicit, deterministic timestamp trail so the
 * turnaround maths can be asserted against exact minute values.
 */
function ops_lab_order(string $test, int $toSample, int $toResult, int $toVerify, string $status = LabOrder::STATUS_COMPLETED): LabOrder
{
    $orderedAt = now()->subDays(2)->startOfDay();

    $order = LabOrder::factory()->create([
        'test_name' => $test,
        'status' => $status,
        'created_at' => $orderedAt,
        'updated_at' => $orderedAt,
        'sample_collected_at' => $orderedAt->copy()->addMinutes($toSample),
        'recorded_at' => $orderedAt->copy()->addMinutes($toSample + $toResult),
        'verified_at' => $orderedAt->copy()->addMinutes($toSample + $toResult + $toVerify),
        'result' => 'Result ref OK',
    ]);

    return $order->refresh();
}

/**
 * A queue check-in plus the encounter that started the consultation, `wait`
 * minutes later.
 */
function ops_waiting_patient(User $doctor, int $waitMinutes, string $checkedInAt): QueueEntry
{
    $appointment = Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => Patient::factory()->create()->id,
        'scheduled_at' => $checkedInAt,
        'status' => Appointment::STATUS_IN_CONSULTATION,
    ]);

    Encounter::factory()->create([
        'appointment_id' => $appointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $appointment->patient_id,
        'created_at' => (new DateTimeImmutable($checkedInAt))->modify("+{$waitMinutes} minutes"),
    ]);

    return QueueEntry::factory()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
        'status' => QueueEntry::STATUS_COMPLETED,
        'checked_in_at' => $checkedInAt,
    ]);
}

test('lab turnaround computes each milestone with real averages and percentiles', function () {
    $user = ops_user();
    [$from, $to] = ops_range();

    ops_lab_order('Complete Blood Count', 30, 120, 15);
    ops_lab_order('Complete Blood Count', 60, 240, 45);
    ops_lab_order('Urinalysis', 10, 30, 5);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/lab-turnaround?from={$from}&to={$to}")
        ->assertOk();

    $totals = $response->json('data.totals');

    // sample: 30, 60, 10 -> mean 33.3, median 30
    expect($totals['order_count'])->toBe(3)
        ->and($totals['order_to_sample']['sample_size'])->toBe(3)
        ->and((float) $totals['order_to_sample']['average_minutes'])->toBe(33.3)
        ->and((float) $totals['order_to_sample']['p50_minutes'])->toBe(30.0)
        // result: 120, 240, 30 -> mean 130, max 240
        ->and((float) $totals['sample_to_result']['average_minutes'])->toBe(130.0)
        ->and((float) $totals['sample_to_result']['max_minutes'])->toBe(240.0)
        // verification: 15, 45, 5 -> mean 21.7
        ->and((float) $totals['result_to_verification']['average_minutes'])->toBe(21.7)
        // end to end: 165, 345, 45 -> mean 185
        ->and((float) $totals['order_to_verification']['average_minutes'])->toBe(185.0)
        ->and($totals['order_to_verification']['sample_size'])->toBe(3);
});

test('lab turnaround breaks the same figures down per test type', function () {
    $user = ops_user();
    [$from, $to] = ops_range();

    ops_lab_order('Complete Blood Count', 30, 120, 15);
    ops_lab_order('Complete Blood Count', 30, 120, 15);
    ops_lab_order('Thyroid Stimulating Hormone', 90, 600, 120);

    $byTest = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/lab-turnaround?from={$from}&to={$to}")
        ->assertOk()
        ->json('data.by_test');

    expect($byTest)->toHaveCount(2)
        ->and($byTest[0]['test_name'])->toBe('Complete Blood Count')
        ->and($byTest[0]['order_count'])->toBe(2)
        ->and((float) $byTest[0]['sample_to_result']['average_minutes'])->toBe(120.0)
        ->and($byTest[1]['test_name'])->toBe('Thyroid Stimulating Hormone')
        ->and($byTest[1]['order_count'])->toBe(1)
        ->and((float) $byTest[1]['sample_to_result']['average_minutes'])->toBe(600.0);
});

test('lab turnaround excludes an order that has not reached a milestone rather than counting it as a long wait', function () {
    $user = ops_user();
    [$from, $to] = ops_range();

    ops_lab_order('Complete Blood Count', 30, 120, 15);

    // Ordered today, sample not yet collected: nothing downstream can be timed.
    $orderedAt = now()->subHour();
    LabOrder::factory()->create([
        'test_name' => 'Complete Blood Count',
        'status' => LabOrder::STATUS_PENDING,
        'created_at' => $orderedAt,
        'updated_at' => $orderedAt,
        'sample_collected_at' => null,
        'recorded_at' => null,
        'verified_at' => null,
    ]);

    $totals = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/lab-turnaround?from={$from}&to={$to}")
        ->assertOk()
        ->json('data.totals');

    expect($totals['order_count'])->toBe(2)
        ->and($totals['order_to_sample']['sample_size'])->toBe(1)
        ->and((float) $totals['order_to_sample']['average_minutes'])->toBe(30.0)
        ->and($totals['order_to_verification']['sample_size'])->toBe(1);
});

test('lab turnaround ignores cancelled orders and orders outside the range', function () {
    $user = ops_user();
    [$from, $to] = ops_range();

    ops_lab_order('Complete Blood Count', 30, 120, 15);
    ops_lab_order('Complete Blood Count', 30, 120, 15, LabOrder::STATUS_CANCELLED);

    $old = now()->subMonths(3)->startOfDay();
    LabOrder::factory()->create([
        'test_name' => 'Complete Blood Count',
        'status' => LabOrder::STATUS_COMPLETED,
        'created_at' => $old,
        'updated_at' => $old,
    ]);

    $totals = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/lab-turnaround?from={$from}&to={$to}")
        ->assertOk()
        ->json('data.totals');

    expect($totals['order_count'])->toBe(1);
});

test('lab turnaround reports null figures rather than a fabricated zero when nothing was measured', function () {
    $user = ops_user();
    [$from, $to] = ops_range();

    $totals = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/lab-turnaround?from={$from}&to={$to}")
        ->assertOk()
        ->json('data.totals');

    expect($totals['order_count'])->toBe(0)
        ->and($totals['order_to_sample']['sample_size'])->toBe(0)
        ->and($totals['order_to_sample']['average_minutes'])->toBeNull()
        ->and($totals['order_to_sample']['p90_minutes'])->toBeNull();
});

test('wait time measures check-in to encounter start and groups by doctor, branch and day', function () {
    $user = ops_user();
    $doctor = User::factory()->create(['role' => 'Doctor', 'name' => 'Dr. Ayesha Noor']);
    $other = User::factory()->create(['role' => 'Doctor', 'name' => 'Dr. Bilal Khan']);
    [$from, $to] = ops_range();

    $today = now()->startOfDay();
    ops_waiting_patient($doctor, 10, $today->copy()->addHours(9)->toDateTimeString());
    ops_waiting_patient($doctor, 30, $today->copy()->addHours(11)->toDateTimeString());
    ops_waiting_patient($other, 50, $today->copy()->addHours(12)->toDateTimeString());

    $data = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/wait-times?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');

    expect($data['totals']['sample_size'])->toBe(3)
        ->and((float) $data['totals']['average_minutes'])->toBe(30.0)
        ->and((float) $data['totals']['p50_minutes'])->toBe(30.0)
        ->and((float) $data['totals']['max_minutes'])->toBe(50.0)
        ->and($data['by_doctor'])->toHaveCount(2);

    $noor = collect($data['by_doctor'])->firstWhere('doctor_name', 'Dr. Ayesha Noor');
    expect($noor['sample_size'])->toBe(2)
        ->and((float) $noor['average_minutes'])->toBe(20.0)
        ->and((float) $noor['max_minutes'])->toBe(30.0);

    expect($data['by_day'])->toHaveCount(1)
        ->and($data['by_day'][0]['date'])->toBe(now()->toDateString())
        ->and($data['by_day'][0]['sample_size'])->toBe(3);
});

test('wait time excludes a queue entry with no encounter because the visit never started', function () {
    $user = ops_user();
    [$from, $to] = ops_range();

    $appointment = Appointment::factory()->create([
        'patient_id' => Patient::factory()->create()->id,
        'status' => Appointment::STATUS_ARRIVED,
    ]);

    QueueEntry::factory()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
        'status' => QueueEntry::STATUS_WAITING,
        'checked_in_at' => now()->startOfDay()->addHours(9)->toDateTimeString(),
    ]);

    ops_waiting_patient(User::factory()->create(['role' => 'Doctor']), 20, now()->startOfDay()->addHours(10)->toDateTimeString());

    $data = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/wait-times?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');

    expect($data['totals']['sample_size'])->toBe(1)
        ->and((float) $data['totals']['average_minutes'])->toBe(20.0);
});

test('wait time drops a negative gap and reports how many rows were excluded', function () {
    $user = ops_user();
    $doctor = User::factory()->create(['role' => 'Doctor']);
    [$from, $to] = ops_range();

    ops_waiting_patient($doctor, 25, now()->startOfDay()->addHours(9)->toDateTimeString());

    // An encounter stamped before the check-in is a data-entry fault, not a
    // wait. It must not drag the average below zero.
    $appointment = Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => Patient::factory()->create()->id,
        'status' => Appointment::STATUS_IN_CONSULTATION,
    ]);

    $checkIn = now()->startOfDay()->addHours(11);
    Encounter::factory()->create([
        'appointment_id' => $appointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $appointment->patient_id,
        'created_at' => $checkIn->copy()->subMinutes(30),
    ]);

    QueueEntry::factory()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
        'status' => QueueEntry::STATUS_COMPLETED,
        'checked_in_at' => $checkIn->toDateTimeString(),
    ]);

    $data = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/wait-times?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');

    expect($data['excluded_negative_rows'])->toBe(1)
        ->and($data['totals']['sample_size'])->toBe(1)
        ->and((float) $data['totals']['average_minutes'])->toBe(25.0);
});

test('wait time excludes a cancelled queue entry', function () {
    $user = ops_user();
    $doctor = User::factory()->create(['role' => 'Doctor']);
    [$from, $to] = ops_range();

    ops_waiting_patient($doctor, 20, now()->startOfDay()->addHours(9)->toDateTimeString());

    $appointment = Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => Patient::factory()->create()->id,
    ]);
    Encounter::factory()->create([
        'appointment_id' => $appointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $appointment->patient_id,
        'created_at' => now()->startOfDay()->addHours(11),
    ]);
    QueueEntry::factory()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
        'status' => QueueEntry::STATUS_CANCELLED,
        'checked_in_at' => now()->startOfDay()->addHours(10)->toDateTimeString(),
    ]);

    $data = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/wait-times?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');

    expect($data['totals']['sample_size'])->toBe(1);
});

test('lead conversion counts a lead that became a completed visit', function () {
    $user = ops_user();
    [$from, $to] = ops_range();

    $patient = Patient::factory()->create(['phone' => '+15551110001', 'name' => 'Hira Siddiqui']);
    $leadAt = now()->subDays(3);

    $lead = Lead::factory()->create([
        'name' => 'Hira Siddiqui',
        'phone' => '+15551110001',
        'source' => Lead::SOURCE_WEBSITE,
        'status' => Lead::STATUS_CONVERTED,
        'created_at' => $leadAt,
        'updated_at' => $leadAt,
    ]);

    Appointment::factory()->create([
        'patient_id' => $patient->id,
        'status' => Appointment::STATUS_COMPLETED,
        'scheduled_at' => $leadAt->copy()->addDay(),
        'created_at' => $leadAt->copy()->addHours(2),
        'updated_at' => $leadAt->copy()->addHours(2),
    ]);

    expect($lead->exists)->toBeTrue();

    $data = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/lead-conversion?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');

    expect($data['totals']['lead_count'])->toBe(1)
        ->and($data['totals']['booked_count'])->toBe(1)
        ->and($data['totals']['completed_count'])->toBe(1)
        ->and((float) $data['totals']['booking_rate_pct'])->toBe(100.0)
        ->and((float) $data['totals']['completion_rate_pct'])->toBe(100.0)
        ->and($data['by_source'])->toHaveCount(1)
        ->and($data['by_source'][0]['source'])->toBe(Lead::SOURCE_WEBSITE);
});

test('lead conversion reports rates per source across a mixed pipeline', function () {
    $user = ops_user();
    [$from, $to] = ops_range();
    $leadAt = now()->subDays(2);

    // Website lead that booked but has not completed.
    $bookedPatient = Patient::factory()->create(['phone' => '+15551110002']);
    Lead::factory()->create([
        'name' => 'Booked Only', 'phone' => '+15551110002',
        'source' => Lead::SOURCE_WEBSITE, 'status' => Lead::STATUS_QUALIFIED,
        'created_at' => $leadAt, 'updated_at' => $leadAt,
    ]);
    Appointment::factory()->create([
        'patient_id' => $bookedPatient->id,
        'status' => Appointment::STATUS_CONFIRMED,
        'created_at' => $leadAt->copy()->addHours(1), 'updated_at' => $leadAt->copy()->addHours(1),
    ]);

    // Referral lead that never booked.
    Lead::factory()->create([
        'name' => 'Never Booked', 'phone' => '+15551110003',
        'source' => Lead::SOURCE_REFERRAL, 'status' => Lead::STATUS_CLOSED_LOST,
        'created_at' => $leadAt, 'updated_at' => $leadAt,
    ]);

    $data = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/lead-conversion?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');

    expect($data['totals']['lead_count'])->toBe(2)
        ->and($data['totals']['booked_count'])->toBe(1)
        ->and((float) $data['totals']['booking_rate_pct'])->toBe(50.0)
        ->and((float) $data['totals']['completion_rate_pct'])->toBe(0.0);

    $website = collect($data['by_source'])->firstWhere('source', Lead::SOURCE_WEBSITE);
    $referral = collect($data['by_source'])->firstWhere('source', Lead::SOURCE_REFERRAL);

    expect($website['lead_count'])->toBe(1)
        ->and((float) $website['booking_rate_pct'])->toBe(100.0)
        ->and($website['completed_count'])->toBe(0)
        ->and($referral['lead_count'])->toBe(1)
        ->and((float) $referral['booking_rate_pct'])->toBe(0.0);
});

test('lead conversion does not credit a lead for an appointment that predates it', function () {
    $user = ops_user();
    [$from, $to] = ops_range();

    $patient = Patient::factory()->create(['phone' => '+15551110004', 'name' => 'Existing Patient']);
    $longAgo = now()->subMonths(2);

    // The patient already had a completed visit before the lead ever arrived.
    Appointment::factory()->create([
        'patient_id' => $patient->id,
        'status' => Appointment::STATUS_COMPLETED,
        'created_at' => $longAgo,
        'updated_at' => $longAgo,
    ]);

    $leadAt = now()->subDay();
    Lead::factory()->create([
        'name' => 'Existing Patient', 'phone' => '+15551110004',
        'source' => Lead::SOURCE_CALL, 'status' => Lead::STATUS_NEW,
        'created_at' => $leadAt, 'updated_at' => $leadAt,
    ]);

    $data = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/lead-conversion?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');

    expect($data['totals']['lead_count'])->toBe(1)
        ->and($data['totals']['booked_count'])->toBe(0)
        ->and((float) $data['totals']['booking_rate_pct'])->toBe(0.0);
});

test('lead conversion groups by CRM status as well as source', function () {
    $user = ops_user();
    [$from, $to] = ops_range();
    $leadAt = now()->subDay();

    Lead::factory()->create([
        'name' => 'New One', 'phone' => '+15551110005',
        'source' => Lead::SOURCE_EVENT, 'status' => Lead::STATUS_NEW,
        'created_at' => $leadAt, 'updated_at' => $leadAt,
    ]);
    Lead::factory()->create([
        'name' => 'Contacted One', 'phone' => '+15551110006',
        'source' => Lead::SOURCE_EVENT, 'status' => Lead::STATUS_CONTACTED,
        'created_at' => $leadAt, 'updated_at' => $leadAt,
    ]);

    $data = $this->actingAs($user)
        ->getJson("/api/v1/reports/analytics/lead-conversion?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');

    $statuses = collect($data['by_status'])->pluck('status')->all();

    expect($statuses)->toContain(Lead::STATUS_NEW)
        ->and($statuses)->toContain(Lead::STATUS_CONTACTED);
});

test('expiry report separates expired, expiring and batches with no expiry date', function () {
    $user = ops_user();

    $item = ops_item('Amoxicillin 500mg', 100);
    $itemTwo = ops_item('Paracetamol 500mg', 50);

    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-EXPIRED',
        'expiry_date' => now()->subDays(5)->toDateString(),
        'current_quantity' => 10,
    ]);
    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-SOON',
        'expiry_date' => now()->addDays(20)->toDateString(),
        'current_quantity' => 4,
    ]);
    InventoryBatch::factory()->create([
        'inventory_item_id' => $itemTwo->id,
        'batch_number' => 'B-NOEXPIRY',
        'expiry_date' => null,
        'current_quantity' => 6,
    ]);
    // Fully dispensed: no stock at risk, so it must not appear at all.
    InventoryBatch::factory()->create([
        'inventory_item_id' => $itemTwo->id,
        'batch_number' => 'B-EMPTY',
        'expiry_date' => now()->subDay()->toDateString(),
        'current_quantity' => 0,
    ]);

    $data = $this->actingAs($user)
        ->getJson('/api/v1/reports/expiry?within_days=90')
        ->assertOk()
        ->json('data');

    expect($data['summary']['expired_batch_count'])->toBe(1)
        ->and((float) $data['summary']['expired_stock_value'])->toBe(1000.0)
        ->and($data['summary']['expiring_batch_count'])->toBe(1)
        ->and((float) $data['summary']['expiring_stock_value'])->toBe(400.0)
        ->and((float) $data['summary']['at_risk_stock_value'])->toBe(1400.0)
        ->and($data['summary']['no_expiry_date_batch_count'])->toBe(1)
        ->and((float) $data['summary']['no_expiry_date_stock_value'])->toBe(300.0)
        ->and($data['currency'])->toBe('PKR');

    expect(collect($data['expired'])->pluck('batch_number')->all())->toBe(['B-EXPIRED'])
        ->and(collect($data['expiring'])->pluck('batch_number')->all())->toBe(['B-SOON'])
        ->and(collect($data['no_expiry_date'])->pluck('batch_number')->all())->toBe(['B-NOEXPIRY']);

    $expired = $data['expired'][0];
    expect($expired['days_until_expiry'])->toBe(-5)
        ->and($expired['item_name'])->toBe('Amoxicillin 500mg')
        ->and((float) $expired['stock_value'])->toBe(1000.0);
});

test('expiry report honours the as_of date and the within_days horizon', function () {
    $user = ops_user();
    $item = ops_item('Test Item', 10);

    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-INSIDE',
        'expiry_date' => now()->addDays(10)->toDateString(),
        'current_quantity' => 1,
    ]);
    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-OUTSIDE',
        'expiry_date' => now()->addDays(200)->toDateString(),
        'current_quantity' => 1,
    ]);

    $narrow = $this->actingAs($user)
        ->getJson('/api/v1/reports/expiry?within_days=30')
        ->assertOk()
        ->json('data');

    expect(collect($narrow['expiring'])->pluck('batch_number')->all())->toBe(['B-INSIDE']);

    // Shifting the as-of date forward past the near batch flips it to expired.
    $shifted = $this->actingAs($user)
        ->getJson('/api/v1/reports/expiry?as_of='.now()->addDays(20)->toDateString().'&within_days=30')
        ->assertOk()
        ->json('data');

    expect(collect($shifted['expired'])->pluck('batch_number')->all())->toBe(['B-INSIDE'])
        ->and($shifted['as_of'])->toBe(now()->addDays(20)->toDateString());
});

test('expiry report flags a batch that the nightly scan has already alerted on', function () {
    $user = ops_user();
    $item = ops_item('Alerted Item', 20);

    $batch = InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-ALERTED',
        'expiry_date' => now()->addDays(5)->toDateString(),
        'current_quantity' => 3,
    ]);

    StockAlert::factory()->create(['inventory_batch_id' => $batch->id, 'threshold_days' => 30]);

    $row = $this->actingAs($user)
        ->getJson('/api/v1/reports/expiry')
        ->assertOk()
        ->json('data.expiring.0');

    expect($row['has_open_alert'])->toBeTrue();
});

test('expiry export streams a CSV with a bucket per row', function () {
    $user = ops_user();
    $item = ops_item('Syrup', 5);

    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-EXP',
        'expiry_date' => now()->subDay()->toDateString(),
        'current_quantity' => 2,
    ]);
    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-SOON',
        'expiry_date' => now()->addDays(3)->toDateString(),
        'current_quantity' => 2,
    ]);
    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-NULL',
        'expiry_date' => null,
        'current_quantity' => 2,
    ]);

    $response = $this->actingAs($user)->get('/api/v1/reports/expiry/export?within_days=90');

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain('batch_number,item_name,item_sku,current_quantity,expiry_date,days_until_expiry,stock_value,currency,bucket')
        ->toContain('expired')
        ->toContain('expiring')
        ->toContain('no_expiry_date')
        ->toContain('B-EXP')
        ->toContain('B-SOON')
        ->toContain('B-NULL');
});

test('the new reports reject a reversed or malformed date range', function () {
    $user = ops_user();

    foreach (['lab-turnaround', 'wait-times', 'lead-conversion'] as $report) {
        $this->actingAs($user)
            ->getJson("/api/v1/reports/analytics/{$report}?from=2026-10-10&to=2026-10-01")
            ->assertStatus(422);

        $this->actingAs($user)
            ->getJson("/api/v1/reports/analytics/{$report}?from=not-a-date&to=2026-10-01")
            ->assertStatus(422);
    }
});

test('the new reports require the reports.view permission', function () {
    $receptionist = ops_user('Receptionist');

    $this->actingAs($receptionist)->getJson('/api/v1/reports/analytics/lab-turnaround')->assertForbidden();
    $this->actingAs($receptionist)->getJson('/api/v1/reports/analytics/wait-times')->assertForbidden();
    $this->actingAs($receptionist)->getJson('/api/v1/reports/analytics/lead-conversion')->assertForbidden();
    $this->actingAs($receptionist)->getJson('/api/v1/reports/expiry')->assertForbidden();
    $this->actingAs($receptionist)->get('/api/v1/reports/expiry/export')->assertForbidden();
});

test('a doctor with reports.view can read the clinical wait-time and lab reports', function () {
    $doctor = ops_user('Doctor');
    [$from, $to] = ops_range();

    $this->actingAs($doctor)
        ->getJson("/api/v1/reports/analytics/wait-times?from={$from}&to={$to}")
        ->assertOk();

    $this->actingAs($doctor)
        ->getJson("/api/v1/reports/analytics/lab-turnaround?from={$from}&to={$to}")
        ->assertOk();
});

test('the expiry report is scoped to the active branch through the owning item', function () {
    // An Admin is a global viewer and deliberately bypasses branch scoping, so
    // this uses a branch-assigned user to prove the tenant filter works.
    $branch = Branch::factory()->create();
    $otherBranch = Branch::factory()->create();
    $user = User::factory()->create(['role' => 'Accountant', 'branch_id' => $branch->id]);

    $item = ops_item('Own Branch Item', 10);
    $item->forceFill(['branch_id' => $branch->id])->save();

    $otherItem = InventoryItem::factory()->create([
        'item_name' => 'Other Branch Item',
        'unit_price' => 999,
        'stock_quantity' => 0,
        'branch_id' => $otherBranch->id,
    ]);

    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-MINE',
        'expiry_date' => now()->subDay()->toDateString(),
        'current_quantity' => 1,
    ]);

    // The second batch is only reachable through another branch's item. It has
    // to be inserted with the scopes off, because the item itself is hidden
    // from this branch's context.
    InventoryBatch::withoutGlobalScopes()->create([
        'inventory_item_id' => $otherItem->id,
        'batch_number' => 'B-THEIRS',
        'expiry_date' => now()->subDay()->toDateString(),
        'current_quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $data = $this->actingAs($user)
        ->getJson('/api/v1/reports/expiry')
        ->assertOk()
        ->json('data');

    // A multi-branch clinic must not see another branch's stock value.
    expect(collect($data['expired'])->pluck('batch_number')->all())->toBe(['B-MINE'])
        ->and((float) $data['summary']['expired_stock_value'])->toBe(10.0);
});

test('a global-viewer admin still sees every branch in the expiry report', function () {
    $admin = ops_user('Admin');

    $item = ops_item('Visible Item', 10);
    $otherItem = InventoryItem::factory()->create([
        'item_name' => 'Other Item',
        'unit_price' => 999,
        'stock_quantity' => 0,
        'branch_id' => Branch::factory()->create()->id,
    ]);

    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-A',
        'expiry_date' => now()->subDay()->toDateString(),
        'current_quantity' => 1,
    ]);
    InventoryBatch::withoutGlobalScopes()->create([
        'inventory_item_id' => $otherItem->id,
        'batch_number' => 'B-B',
        'expiry_date' => now()->subDay()->toDateString(),
        'current_quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $data = $this->actingAs($admin)
        ->getJson('/api/v1/reports/expiry')
        ->assertOk()
        ->json('data');

    // Scoping must restrict, never silently hide stock from an authorized
    // cross-branch reader.
    expect(collect($data['expired'])->pluck('batch_number')->sort()->values()->all())->toBe(['B-A', 'B-B'])
        ->and((float) $data['summary']['expired_stock_value'])->toBe(1009.0);
});

test('the batch expiry column accepts a missing date', function () {
    $item = ops_item('Undated Item', 10);

    // The column has to be nullable: equipment and non-perishables have no
    // expiry date, and the report must be able to list them rather than 500.
    $batch = InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-UNDATED',
        'expiry_date' => null,
        'current_quantity' => 2,
    ]);

    expect($batch->refresh()->expiry_date)->toBeNull();

    $rows = $this->actingAs(ops_user())
        ->getJson('/api/v1/reports/expiry')
        ->assertOk()
        ->json('data.no_expiry_date');

    expect(collect($rows)->pluck('batch_number')->all())->toBe(['B-UNDATED']);
});

test('rolling the nullable-expiry migration back is refused while undated batches exist', function () {
    $item = ops_item('Undated Item', 10);
    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-UNDATED',
        'expiry_date' => null,
        'current_quantity' => 2,
    ]);

    // Target this migration by path rather than `--step 1`. `--step 1` rolls
    // back whatever migration happens to be newest, so the moment another
    // migration lands after this one the rollback silently stops exercising
    // this guard and the test starts asserting nothing about it. (It did
    // exactly that, and failed, when the medication-reconciliation version
    // column migration was added.) The behaviour under test is "this
    // migration refuses to roll back", not "the newest migration refuses".
    $migration = 'database/migrations/2026_09_29_100000_make_inventory_batch_expiry_date_nullable.php';

    // Restoring NOT NULL cannot hold a NULL date. It must fail loudly rather
    // than silently dropping the row, because a batch silently vanishing is a
    // stock-integrity problem (PRD §8).
    //
    // The refusal is an explicit pre-check in `down()`, not the rebuilt table's
    // NOT NULL constraint raising: under a non-strict MySQL `sql_mode` the
    // constraint violation never fires and NULL is coerced to '0000-00-00',
    // which FEFO would then read as expired since year zero. The message is
    // asserted because the failure has to tell an operator what to do.
    expect(fn () => $this->artisan('migrate:rollback', ['--path' => $migration])->run())
        ->toThrow(RuntimeException::class);

    try {
        $this->artisan('migrate:rollback', ['--path' => $migration])->run();
    } catch (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain('Refusing to roll back the nullable-expiry migration')
            ->toContain('have no expiry date');
    }

    // The row survived the refused rollback.
    expect(InventoryBatch::where('batch_number', 'B-UNDATED')->exists())->toBeTrue();

    // The column is still nullable, and the migration is still recorded as
    // applied: a refusal must not half-apply the schema change.
    expect(Schema::getColumnType('inventory_batches', 'expiry_date'))->toBe('date')
        ->and(DB::table('migrations')
            ->where('migration', '2026_09_29_100000_make_inventory_batch_expiry_date_nullable')
            ->exists())->toBeTrue();

    // Re-applying is a clean, successful path, and the report still lists the
    // undated batch.
    $this->artisan('migrate', ['--path' => $migration])->assertSuccessful();

    $rows = $this->actingAs(ops_user())
        ->getJson('/api/v1/reports/expiry')
        ->assertOk()
        ->json('data.no_expiry_date');

    expect(collect($rows)->pluck('batch_number')->all())->toBe(['B-UNDATED']);
});

test('the nullable-expiry migration rolls back cleanly once every batch is dated', function () {
    $item = ops_item('Dated Item', 10);
    InventoryBatch::factory()->create([
        'inventory_item_id' => $item->id,
        'batch_number' => 'B-DATED',
        'expiry_date' => now()->addYear()->toDateString(),
        'current_quantity' => 2,
    ]);

    // The guard must refuse only while it has to. With every batch dated,
    // restoring NOT NULL is lossless and the rollback has to succeed —
    // otherwise this migration becomes a permanent one-way door that no
    // future migration could build on.
    $migration = 'database/migrations/2026_09_29_100000_make_inventory_batch_expiry_date_nullable.php';

    $this->artisan('migrate:rollback', ['--path' => $migration])->assertSuccessful();

    // The batch survived the rebuild (it is referenced by stock tables, so the
    // rebuild had to preserve it rather than dropping the table's contents).
    expect(InventoryBatch::where('batch_number', 'B-DATED')->exists())->toBeTrue();

    $this->artisan('migrate', ['--path' => $migration])->assertSuccessful();
});
