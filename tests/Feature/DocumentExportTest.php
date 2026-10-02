<?php

use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

test('an admin can download a PDF invoice', function () {
    $user = User::factory()->create(['role' => 'Admin']);
    $invoice = Invoice::factory()->has(Payment::factory(), 'payments')->create();

    $response = $this->actingAs($user)
        ->get("/api/v1/invoices/{$invoice->id}/pdf")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect(str_starts_with($response->getContent(), '%PDF'))->toBeTrue();
});

test('an admin can download a PDF prescription', function () {
    $user = User::factory()->create(['role' => 'Admin']);
    $prescription = Prescription::factory()->has(PrescriptionItem::factory(), 'items')->create();

    $response = $this->actingAs($user)
        ->get("/api/v1/prescriptions/{$prescription->id}/pdf")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect(str_starts_with($response->getContent(), '%PDF'))->toBeTrue();
});

test('the financial export returns a UTF-8 CSV with totals for the period', function () {
    $user = User::factory()->create(['role' => 'Admin']);
    $invoice = Invoice::factory()->create([
        'issued_at' => now()->format('Y-m-d H:i:s'),
    ]);

    $response = $this->actingAs($user)
        ->get('/api/v1/reports/financial/export?period=daily')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect($response->headers->get('Content-Disposition'))->toContain('financial_export_daily_')
        ->toContain('.csv');
    expect($response->streamedContent())->toContain($invoice->invoice_number)
        ->toContain('TOTAL');
});

test('the inventory export returns a CSV for the audit trail', function () {
    $user = User::factory()->create(['role' => 'Admin']);
    $item = InventoryItem::factory()->lowStock()->create();

    $response = $this->actingAs($user)
        ->get('/api/v1/reports/inventory/export')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF");
    expect($csv)->toContain($item->sku, $item->item_name, 'LOW STOCK', 'TOTAL');
});

test('users without the billing permission cannot reach invoice documents', function () {
    $receptionist = User::factory()->create(['role' => 'Receptionist']);
    $invoice = Invoice::factory()->create();

    $this->actingAs($receptionist)
        ->get("/api/v1/invoices/{$invoice->id}/pdf")
        ->assertForbidden();
});

test('users without the reports permission cannot download exports', function () {
    $receptionist = User::factory()->create(['role' => 'Receptionist']);

    $this->actingAs($receptionist)
        ->get('/api/v1/reports/financial/export')
        ->assertForbidden();

    $this->actingAs($receptionist)
        ->get('/api/v1/reports/inventory/export')
        ->assertForbidden();
});
