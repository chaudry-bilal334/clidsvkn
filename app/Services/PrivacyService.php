<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\DataExportRequest;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrivacyService
{
    /**
     * Generate a comprehensive data export for a patient (GDPR Article 20 - Right to Data Portability).
     *
     * Compiles patient profile, appointments, prescriptions, invoices, and lab orders
     * into a structured JSON package stored securely.
     */
    public function generatePatientExport(DataExportRequest $exportRequest): DataExportRequest
    {
        $exportRequest->update(['status' => DataExportRequest::STATUS_PROCESSING]);

        try {
            $patient = $exportRequest->patient()->with([
                'appointments.doctor:id,name',
                'prescriptions.items',
                'invoices.items',
                'labOrders',
            ])->firstOrFail();

            $data = [
                'patient' => [
                    'id' => $patient->id,
                    'name' => $patient->name,
                    'email' => $patient->email,
                    'phone' => $patient->phone,
                    'date_of_birth' => $patient->date_of_birth?->toDateString(),
                    'gender' => $patient->gender,
                    'address' => $patient->address,
                    'created_at' => $patient->created_at?->toIso8601String(),
                ],
                'appointments' => $patient->appointments->map(fn (Appointment $a) => [
                    'id' => $a->id,
                    'scheduled_at' => $a->scheduled_at?->toIso8601String(),
                    'status' => $a->status,
                    'reason' => $a->reason,
                    'notes' => $a->notes,
                    'doctor' => $a->doctor ? [
                        'id' => $a->doctor->id,
                        'name' => $a->doctor->name,
                    ] : null,
                ])->values(),
                'prescriptions' => $patient->prescriptions->map(fn (Prescription $p) => [
                    'id' => $p->id,
                    'prescription_number' => $p->prescription_number,
                    'status' => $p->status,
                    'notes' => $p->notes,
                    'issued_at' => $p->issued_at?->toIso8601String(),
                    'doctor' => $p->doctor ? [
                        'id' => $p->doctor->id,
                        'name' => $p->doctor->name,
                    ] : null,
                    'items' => $p->items->map(fn (PrescriptionItem $i) => [
                        'medicine_name' => $i->medicine_name,
                        'dosage' => $i->dosage,
                        'frequency' => $i->frequency,
                        'duration' => $i->duration,
                        'instructions' => $i->instructions,
                        'quantity' => $i->quantity,
                    ])->values(),
                ])->values(),
                'invoices' => $patient->invoices->map(fn (Invoice $inv) => [
                    'id' => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'status' => $inv->status,
                    'subtotal' => (float) $inv->subtotal,
                    'tax' => (float) $inv->tax,
                    'total' => (float) $inv->total,
                    'amount_paid' => (float) $inv->amount_paid,
                    'balance_due' => (float) $inv->balance_due,
                    'issued_at' => $inv->issued_at?->toIso8601String(),
                    'items' => $inv->items->map(fn ($item) => [
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'line_total' => (float) $item->line_total,
                    ])->values(),
                ])->values(),
                'lab_orders' => $patient->labOrders->map(fn (LabOrder $lo) => [
                    'id' => $lo->id,
                    'test_name' => $lo->test_name,
                    'status' => $lo->status,
                    'result' => $lo->result,
                    'requested_at' => $lo->requested_at?->toIso8601String(),
                    'recorded_at' => $lo->recorded_at?->toIso8601String(),
                ])->values(),
                'export_metadata' => [
                    'generated_at' => now()->toIso8601String(),
                    'requested_by' => $exportRequest->requester?->name,
                    'export_version' => '1.0',
                ],
            ];

            $filename = 'patient-export-'.$patient->id.'-'.now()->format('YmdHis').'.json';
            $path = 'exports/'.$filename;

            Storage::disk('local')->put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $exportRequest->update([
                'status' => DataExportRequest::STATUS_COMPLETED,
                'file_path' => $path,
            ]);

            return $exportRequest;
        } catch (\Throwable $e) {
            $exportRequest->update([
                'status' => DataExportRequest::STATUS_FAILED,
                'file_path' => null,
            ]);

            throw $e;
        }
    }

    /**
     * Anonymize a patient's personal data (GDPR Article 17 - Right to Erasure / Right to be Forgotten).
     *
     * Scrubs PII (name, email, phone, address) while preserving immutable clinical audit trails
     * by keeping foreign keys and medical records intact but de-identified.
     */
    public function anonymizePatient(Patient $patient, User $requester): Patient
    {
        $anonymizedName = 'Patient-'.Str::upper(Str::random(8));
        $anonymizedEmail = 'anonymized-'.$patient->id.'@clinic.local';
        $anonymizedPhone = '+1-555-000-0000';

        $patient->update([
            'name' => $anonymizedName,
            'email' => $anonymizedEmail,
            'phone' => $anonymizedPhone,
            'address' => 'REDACTED',
        ]);

        // If the patient has a linked portal user, anonymize that too
        if ($patient->user) {
            $patient->user->update([
                'name' => $anonymizedName,
                'email' => $anonymizedEmail,
                'phone' => $anonymizedPhone,
            ]);
        }

        // Log the anonymization action for audit trail (using Laravel's built-in logging)
        \Log::channel('audit')->info('Patient data anonymized per GDPR right to erasure', [
            'action' => 'patient_anonymized',
            'original_id' => $patient->id,
            'gdpr_article' => 'Article 17',
            'requester_id' => $requester->id,
            'requester_name' => $requester->name,
        ]);

        return $patient->refresh();
    }
}
