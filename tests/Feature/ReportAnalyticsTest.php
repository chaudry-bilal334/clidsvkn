<?php

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\DoctorSchedule;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function report_user(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

function report_invoice(float $total = 100.00, float $paid = 0.00): Invoice
{
    return Invoice::factory()->create([
        'subtotal' => $total,
        'tax' => 0,
        'total' => $total,
        'amount_paid' => $paid,
        'balance_due' => $total - $paid,
        'status' => $paid > 0 ? Invoice::STATUS_PAID : Invoice::STATUS_GENERATED,
        'issued_at' => now(),
    ]);
}

test('financial report computes revenue today, month, outstanding and invoice count', function () {
    $user = report_user();

    $invoice1 = report_invoice(100.00, 100.00);
    $invoice2 = report_invoice(200.00, 50.00);
    $invoice3 = report_invoice(300.00, 0.00);

    Payment::factory()->create([
        'invoice_id' => $invoice1->id,
        'amount' => 100.00,
        'method' => Payment::METHOD_CASH,
        'status' => Payment::STATUS_RECORDED,
        'paid_at' => now(),
    ]);
    Payment::factory()->create([
        'invoice_id' => $invoice2->id,
        'amount' => 50.00,
        'method' => Payment::METHOD_CASH,
        'status' => Payment::STATUS_RECORDED,
        'paid_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reports/financial')
        ->assertOk();

    $data = $response->json('data');

    expect($data[0]['label'])->toBe('Revenue Today')
        ->and((float) $data[0]['total'])->toBe(150.0)
        ->and($data[1]['label'])->toBe('Revenue This Month')
        ->and((float) $data[1]['total'])->toBe(150.0)
        ->and($data[2]['label'])->toBe('Outstanding Balance')
        ->and((float) $data[2]['total'])->toBe(450.0)
        ->and($data[3]['label'])->toBe('Invoices Issued (MTD)')
        ->and($data[3]['total'])->toBe(3);
});

test('financial report returns invoiced today separately from revenue collected', function () {
    $user = report_user();

    // A finalized invoice is billed before it is paid, so "invoiced today" and
    // "revenue today" must not be the same figure (Task 2 fix: the card used to
    // render a hardcoded $0).
    $paidToday = report_invoice(100.00, 100.00);
    $unpaidToday = report_invoice(400.00, 0.00);
    $oldInvoice = Invoice::factory()->create([
        'subtotal' => 900.00,
        'tax' => 0,
        'total' => 900.00,
        'amount_paid' => 900.00,
        'balance_due' => 0,
        'status' => Invoice::STATUS_PAID,
        'issued_at' => now()->subDays(10),
    ]);

    Payment::factory()->create([
        'invoice_id' => $paidToday->id,
        'amount' => 100.00,
        'method' => Payment::METHOD_CASH,
        'status' => Payment::STATUS_RECORDED,
        'paid_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reports/financial')
        ->assertOk();

    // Invoiced today: 100 + 400. The 10-day-old invoice must not be counted.
    expect((float) $response->json('meta.invoiced_today'))->toBe(500.0)
        ->and($response->json('meta.invoiced_today_date'))->toBe(now()->toDateString())
        ->and((float) $response->json('data.0.total'))->toBe(100.0)
        ->and($oldInvoice->exists)->toBeTrue();
});

test('financial and revenue analytics report the configured clinic currency', function () {
    $user = report_user();

    $this->actingAs($user)
        ->getJson('/api/v1/reports/financial')
        ->assertOk()
        ->assertJsonPath('meta.currency', 'PKR');

    $this->actingAs($user)
        ->getJson('/api/v1/reports/analytics/revenue')
        ->assertOk()
        ->assertJsonPath('meta.currency', 'PKR');
});

test('the reported currency follows configuration rather than a hardcoded symbol', function () {
    $user = report_user();
    config(['clinic.currency' => 'AED']);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/financial')
        ->assertOk()
        ->assertJsonPath('meta.currency', 'AED');
});

test('an empty financial report still returns a zero invoiced figure rather than a placeholder', function () {
    $user = report_user();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reports/financial')
        ->assertOk();

    // JSON cannot distinguish 0 from 0.0, so assert the numeric value.
    expect((float) $response->json('meta.invoiced_today'))->toBe(0.0);
});

test('revenue analytics computes total, payment count, daily series and method breakdown', function () {
    $user = report_user();

    $invoice = report_invoice(300.00, 300.00);

    Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 100.00,
        'method' => Payment::METHOD_CASH,
        'status' => Payment::STATUS_RECORDED,
        'paid_at' => now()->subDays(2),
    ]);
    Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 200.00,
        'method' => Payment::METHOD_CARD,
        'status' => Payment::STATUS_RECORDED,
        'paid_at' => now()->subDays(1),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reports/analytics/revenue')
        ->assertOk();

    $data = $response->json('data');

    expect((float) $data['total_revenue'])->toBe(300.0)
        ->and($data['payment_count'])->toBe(2)
        ->and($data['by_payment_method'])->toHaveCount(2);

    $methods = collect($data['by_payment_method'])->pluck('method', 'total')->all();
    expect($methods[100.0])->toBe(Payment::METHOD_CASH)
        ->and($methods[200.0])->toBe(Payment::METHOD_CARD);

    $dailyTotals = collect($data['daily'])->pluck('total', 'date')->all();
    expect((float) $dailyTotals[now()->subDays(2)->toDateString()])->toBe(100.0)
        ->and((float) $dailyTotals[now()->subDays(1)->toDateString()])->toBe(200.0);

    expect($data['forecast']['method'])->toBe('linear-regression')
        ->and($data['forecast']['horizon_days'])->toBe(30);
});

test('utilization analytics computes booked slots, capacity and utilization pct', function () {
    $user = report_user();
    $doctor = User::factory()->create(['role' => 'Doctor', 'name' => 'Dr. Test']);

    DoctorSchedule::factory()->create([
        'doctor_id' => $doctor->id,
        'day_of_week' => now()->dayOfWeek,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'is_active' => true,
    ]);

    $branch = Branch::factory()->create();

    Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => 1,
        'branch_id' => $branch->id,
        'status' => 'completed',
        'scheduled_at' => now()->setTime(10, 0),
    ]);
    Appointment::factory()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => 2,
        'branch_id' => $branch->id,
        'status' => 'completed',
        'scheduled_at' => now()->setTime(11, 0),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reports/analytics/utilization')
        ->assertOk();

    $data = $response->json('data');

    expect($data['doctors'])->toHaveCount(1);
    $doc = $data['doctors'][0];
    expect($doc['doctor_name'])->toBe('Dr. Test')
        ->and($doc['booked_slots'])->toBe(2)
        ->and($doc['available_slots'])->toBeGreaterThan(0)
        ->and($doc['utilization_pct'])->toBeGreaterThan(0.0);
});

test('conversion funnel computes cumulative counts across appointment statuses', function () {
    $user = report_user();
    $branch = Branch::factory()->create();

    Appointment::factory()->create(['status' => 'booked', 'scheduled_at' => now(), 'branch_id' => $branch->id, 'patient_id' => 1]);
    Appointment::factory()->create(['status' => 'confirmed', 'scheduled_at' => now(), 'branch_id' => $branch->id, 'patient_id' => 2]);
    Appointment::factory()->create(['status' => 'completed', 'scheduled_at' => now(), 'branch_id' => $branch->id, 'patient_id' => 3]);
    Appointment::factory()->create(['status' => 'cancelled', 'scheduled_at' => now(), 'branch_id' => $branch->id, 'patient_id' => 4]);
    Appointment::factory()->create(['status' => 'no_show', 'scheduled_at' => now(), 'branch_id' => $branch->id, 'patient_id' => 5]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reports/analytics/funnel')
        ->assertOk();

    $funnel = collect($response->json('data.funnel'))->pluck('count', 'status')->all();

    expect($funnel['booked'])->toBe(3)
        ->and($funnel['confirmed'])->toBe(2)
        ->and($funnel['arrived'])->toBe(1)
        ->and($funnel['completed'])->toBe(1)
        ->and($funnel['cancelled'])->toBe(1)
        ->and($funnel['no_show'])->toBe(1);
});

test('branch volumes computes visit counts and unique patients per branch', function () {
    $user = report_user();
    $branchA = Branch::factory()->create(['name' => 'Branch A']);
    $branchB = Branch::factory()->create(['name' => 'Branch B']);

    Appointment::factory()->create(['status' => 'completed', 'scheduled_at' => now(), 'branch_id' => $branchA->id, 'patient_id' => 1]);
    Appointment::factory()->create(['status' => 'completed', 'scheduled_at' => now(), 'branch_id' => $branchA->id, 'patient_id' => 1]);
    Appointment::factory()->create(['status' => 'completed', 'scheduled_at' => now(), 'branch_id' => $branchA->id, 'patient_id' => 2]);
    Appointment::factory()->create(['status' => 'completed', 'scheduled_at' => now(), 'branch_id' => $branchB->id, 'patient_id' => 3]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reports/analytics/branch-volumes')
        ->assertOk();

    $volumes = collect($response->json('data'))->keyBy('branch_name');

    expect($volumes['Branch A']['visit_count'])->toBe(3)
        ->and($volumes['Branch A']['unique_patients'])->toBe(2)
        ->and($volumes['Branch B']['visit_count'])->toBe(1)
        ->and($volumes['Branch B']['unique_patients'])->toBe(1);
});
