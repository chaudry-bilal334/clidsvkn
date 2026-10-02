<?php

namespace Database\Seeders;

use App\Models\Allergen;
use App\Models\Appointment;
use App\Models\DosageRule;
use App\Models\DrugInteractionRule;
use App\Models\InsuranceProvider;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabOrder;
use App\Models\NotificationTemplate;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Default seeded role accounts. All share the password "password" so
     * automated (E2E) test suites can authenticate deterministically.
     */
    private const DEMO_ACCOUNTS = [
        ['name' => 'Admin', 'email' => 'admin@clinic.com', 'role' => 'Admin'],
        ['name' => 'Doctor', 'email' => 'doctor@clinic.com', 'role' => 'Doctor'],
        ['name' => 'Receptionist', 'email' => 'receptionist@clinic.com', 'role' => 'Receptionist'],
        ['name' => 'Accountant', 'email' => 'accountant@clinic.com', 'role' => 'Accountant'],
        ['name' => 'Pharmacist', 'email' => 'pharmacist@clinic.com', 'role' => 'Pharmacist'],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (self::DEMO_ACCOUNTS as $account) {
            $this->upsertUser($account['name'], $account['email'], $account['role']);
        }

        $this->upsertUser('Test User', 'test@example.com', 'Receptionist');

        $this->seedPatients();
        $this->seedPortalPatient();
        $this->seedAppointments();
        $this->seedInsuranceProviders();
        $this->seedClinicalReferenceData();
        $this->seedInventory();
        $this->seedInvoices();
        $this->seedPrescriptions();
        $this->seedLabOrders();
        $this->seedNotificationTemplates();
    }

    /**
     * Deterministic demo patients keyed by email so re-seeding never duplicates.
     *
     * @return array<int, Patient>
     */
    private function seedPatients(): array
    {
        $patients = [
            ['name' => 'Alice Johnson', 'email' => 'alice@example.com', 'phone' => '+1 (555) 210-0041', 'date_of_birth' => '1988-04-12', 'gender' => 'female', 'address' => '241 Birch Ave, Healthview'],
            ['name' => 'Bob Martinez', 'email' => 'bob@example.com', 'phone' => '+1 (555) 210-0042', 'date_of_birth' => '1975-09-03', 'gender' => 'male', 'address' => '88 Cedar Ln, Healthview'],
            ['name' => 'Chloe Kim', 'email' => 'chloe@example.com', 'phone' => '+1 (555) 210-0043', 'date_of_birth' => '1994-01-27', 'gender' => 'female', 'address' => '12 Maple Dr, Healthview'],
        ];

        return collect($patients)
            ->map(fn (array $data) => Patient::updateOrCreate(
                ['email' => $data['email']],
                $data,
            ))
            ->values()
            ->all();
    }

    /**
     * Portal login for the demo patient (Alice), so the Vue/React portal SPA
     * can be exercised end-to-end with the same deterministic credentials.
     */
    private function seedPortalPatient(): void
    {
        $alice = Patient::where('email', 'alice@example.com')->first();

        if ($alice === null) {
            return;
        }

        User::updateOrCreate(
            ['email' => 'patient@clinic.com'],
            [
                'name' => $alice->name,
                'email' => 'patient@clinic.com',
                'password' => 'password',
                'email_verified_at' => now(),
                'role' => 'Patient',
                'patient_id' => $alice->id,
                'phone' => $alice->phone,
            ],
        );
    }

    /**
     * Appointments spread across the last two weeks plus upcoming slots.
     */
    private function seedAppointments(): void
    {
        $patients = Patient::all();

        $doctor = $this->doctorUser();

        $slots = [
            ['day' => -8, 'hour' => 9, 'status' => 'completed', 'reason' => 'Routine check-up'],
            ['day' => -8, 'hour' => 11, 'status' => 'completed', 'reason' => 'Persistent headache'],
            ['day' => -3, 'hour' => 10, 'status' => 'completed', 'reason' => 'Blood pressure review'],
            ['day' => -1, 'hour' => 14, 'status' => 'cancelled', 'reason' => 'Flu symptoms'],
            ['day' => 1, 'hour' => 9, 'status' => 'booked', 'reason' => 'Annual physical'],
            ['day' => 3, 'hour' => 13, 'status' => 'booked', 'reason' => 'Follow-up consultation'],
        ];

        $patients->each(function (Patient $patient, int $index) use ($doctor, $slots): void {
            $slot = $slots[$index % count($slots)];

            Appointment::updateOrCreate(
                ['patient_id' => $patient->id, 'scheduled_at' => $date = now()->addDays($slot['day'])->setTime($slot['hour'], 0)->format('Y-m-d H:i:s')],
                [
                    'doctor_id' => $doctor->id,
                    'scheduled_at' => $date,
                    'status' => $slot['status'],
                    'reason' => $slot['reason'],
                    'notes' => 'Seeded demo record.',
                ],
            );
        });
    }

    /**
     * Pharmacy inventory including a couple of low-stock items the low-stock
     * report and the pharmacy dashboard rely on.
     */
    private function seedInventory(): void
    {
        $items = [
            ['sku' => 'MED-PARA-0500', 'item_name' => 'Paracetamol 500mg Tablets', 'category' => 'medicine', 'unit_price' => 8.50, 'stock_quantity' => 140, 'reorder_level' => 40],
            ['sku' => 'MED-AMOX-0500', 'item_name' => 'Amoxicillin 500mg Capsules', 'category' => 'medicine', 'unit_price' => 15.00, 'stock_quantity' => 12, 'reorder_level' => 30],
            ['sku' => 'SUP-GLOV-M', 'item_name' => 'Surgical Gloves (Box of 100)', 'category' => 'supply', 'unit_price' => 9.75, 'stock_quantity' => 4, 'reorder_level' => 10],
            ['sku' => 'MED-IBU-0200', 'item_name' => 'Ibuprofen 200mg Tablets', 'category' => 'medicine', 'unit_price' => 6.25, 'stock_quantity' => 90, 'reorder_level' => 25],
            ['sku' => 'SUP-SYRG-1ML', 'item_name' => 'Insulin Syringes 1ml (Box)', 'category' => 'supply', 'unit_price' => 11.30, 'stock_quantity' => 45, 'reorder_level' => 20],
            ['sku' => 'EQ-BPM-BP-01', 'item_name' => 'Digital Blood Pressure Monitor', 'category' => 'equipment', 'unit_price' => 89.00, 'stock_quantity' => 2, 'reorder_level' => 1],
        ];

        foreach ($items as $item) {
            InventoryItem::updateOrCreate(
                ['sku' => $item['sku']],
                ['last_restocked' => now()->subDays(rand(3, 21))->toDateString()] + $item,
            );
        }
    }

    /**
     * Issued invoices with line items and, for some, recorded payments.
     */
    private function seedInvoices(): void
    {
        $patients = Patient::all();

        $definitions = [
            ['num' => 'INV-DEMO-0001', 'days_ago' => 12, 'paid' => true, 'items' => [['Consultation', 1, 120.00], ['X-Ray Exam', 1, 85.00]]],
            ['num' => 'INV-DEMO-0002', 'days_ago' => 5, 'paid' => true, 'items' => [['Consultation', 1, 120.00], ['Blood Panel', 1, 60.00]]],
            ['num' => 'INV-DEMO-0003', 'days_ago' => 2, 'paid' => false, 'items' => [['Follow-up Visit', 1, 80.00]]],
        ];

        foreach ($definitions as $index => $definition) {
            $taxRate = (float) config('clinic.tax_rate', 13);
            $subtotal = collect($definition['items'])->sum(fn (array $line) => $line[1] * $line[2]);
            $tax = round($subtotal * $taxRate / 100, 2);
            $total = round($subtotal + $tax, 2);

            $invoice = Invoice::updateOrCreate(
                ['invoice_number' => $definition['num']],
                [
                    'patient_id' => $patients[$index % $patients->count()]->id,
                    'patient_name' => $patients[$index % $patients->count()]->name,
                    'status' => $definition['paid'] ? 'paid' : 'generated',
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                    'amount_paid' => $definition['paid'] ? $total : 0,
                    'balance_due' => $definition['paid'] ? 0 : $total,
                    'issued_at' => now()->subDays($definition['days_ago'])->format('Y-m-d H:i:s'),
                ],
            );

            $invoice->items()->delete();

            foreach ($definition['items'] as $line) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $line[0],
                    'quantity' => $line[1],
                    'unit_price' => $line[2],
                    'line_total' => round($line[1] * $line[2], 2),
                ]);
            }

            $invoice->payments()->delete();

            if ($definition['paid']) {
                Payment::create([
                    'invoice_id' => $invoice->id,
                    'amount' => $total,
                    'method' => $index % 2 === 0 ? 'credit_card' : 'cash',
                    'reference' => 'PAY-DEMO-'.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT),
                    'paid_at' => $invoice->issued_at?->addDays(rand(0, 2))->format('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    /**
     * Prescriptions written by the demo doctor, dispensed and pending.
     */
    private function seedPrescriptions(): void
    {
        $patients = Patient::all();
        $doctor = $this->doctorUser();

        $definitions = [
            ['num' => 'RX-DEMO-0001', 'status' => 'dispensed', 'items' => [['Amoxicillin 500mg', '500 mg', 'three times daily', '7 days', 'Take with food.', 21]]],
            ['num' => 'RX-DEMO-0002', 'status' => 'pending', 'items' => [['Ibuprofen 200mg', '200 mg', 'every 6 hours', '5 days', 'Do not take on empty stomach.', 20]]],
        ];

        foreach ($definitions as $index => $definition) {
            $prescription = Prescription::updateOrCreate(
                ['prescription_number' => $definition['num']],
                [
                    'patient_id' => $patients[$index]['id'] ?? $patients[0]->id,
                    'patient_name' => $patients[$index]['name'] ?? $patients[0]->name,
                    'doctor_id' => $doctor->id,
                    'doctor_name' => $doctor->name,
                    'doctor_license_no' => 'LIC-2024-0017',
                    'appointment_id' => null,
                    'status' => $definition['status'],
                    'notes' => 'Recheck in two weeks.',
                    'issued_at' => now()->subDays(rand(1, 10))->format('Y-m-d H:i:s'),
                ],
            );

            $prescription->items()->delete();

            foreach ($definition['items'] as $line) {
                [$medicine, $dosage, $frequency, $duration, $instructions, $quantity] = $line;

                PrescriptionItem::create([
                    'prescription_id' => $prescription->id,
                    'medicine_name' => $medicine,
                    'dosage' => $dosage,
                    'frequency' => $frequency,
                    'duration' => $duration,
                    'instructions' => $instructions,
                    'quantity' => $quantity,
                ]);
            }
        }
    }

    /**
     * A handful of lab orders for the lab-tests report.
     */
    private function seedLabOrders(): void
    {
        $patients = Patient::all();

        $tests = [
            ['Complete Blood Count', 'completed'],
            ['Blood Glucose Fasting', 'completed'],
            ['Cholesterol Panel', 'in_progress'],
            ['Urinalysis', 'pending'],
            ['Thyroid Stimulating Hormone', 'completed'],
        ];

        $patients->each(function (Patient $patient, int $index) use ($tests): void {
            [$name, $status] = $tests[$index % count($tests)];

            LabOrder::updateOrCreate(
                ['patient_id' => $patient->id, 'test_name' => $name],
                [
                    'status' => $status,
                    'result' => $status === 'completed' ? 'Within normal limits.' : null,
                    'recorded_at' => $status === 'completed' ? now()->subDays($index + 2)->format('Y-m-d H:i:s') : null,
                ],
            );
        });
    }

    /**
     * Structured clinical reference data migrated from the CDSS service's
     * former hard-coded lookup arrays into database rows.
     *
     * IMPORTANT (PRD §12/§22): these rows are placeholders that mirror the
     * previously hard-coded safety parameters for development/demo parity.
     * They are NOT clinically approved. Every row is seeded with
     * is_approved = false and must stay that way until the clinic's named
     * qualified clinical lead signs off. The seeder must never set
     * is_approved = true on its own.
     */
    private function seedClinicalReferenceData(): void
    {
        $allergens = [
            ['name' => 'Penicillin', 'family' => 'penicillin', 'aliases' => ['amoxicillin', 'ampicillin', 'penicillin']],
            ['name' => 'Sulfonamide', 'family' => 'sulfa', 'aliases' => ['sulfamethoxazole', 'sulfadiazine', 'sulfasalazine']],
            ['name' => 'NSAID', 'family' => 'nsaid', 'aliases' => ['ibuprofen', 'naproxen', 'aspirin', 'diclofenac']],
        ];

        foreach ($allergens as $allergen) {
            Allergen::updateOrCreate(
                ['name' => $allergen['name']],
                ['family' => $allergen['family'], 'aliases' => $allergen['aliases'], 'is_approved' => false],
            );
        }

        $interactions = [
            ['medicine_a' => 'Warfarin', 'medicine_b' => 'Ibuprofen', 'aliases_a' => ['warfarin', 'coumadin'], 'aliases_b' => ['ibuprofen', 'advil', 'motrin'], 'severity' => 'severe', 'guidance' => 'Potential interaction between Warfarin and Ibuprofen detected; adjust dosing or monitor for adverse effects.'],
            ['medicine_a' => 'Warfarin', 'medicine_b' => 'Aspirin', 'aliases_a' => ['warfarin', 'coumadin'], 'aliases_b' => ['aspirin', 'acetylsalicylic acid', 'asa'], 'severity' => 'severe', 'guidance' => 'Potential interaction between Warfarin and Aspirin detected; adjust dosing or monitor for adverse effects.'],
            ['medicine_a' => 'Warfarin', 'medicine_b' => 'Amoxicillin', 'aliases_a' => ['warfarin', 'coumadin'], 'aliases_b' => ['amoxicillin', 'amoxil'], 'severity' => 'moderate', 'guidance' => 'Potential interaction between Warfarin and Amoxicillin detected; monitor INR closely.'],
            ['medicine_a' => 'Sildenafil', 'medicine_b' => 'Isosorbide', 'aliases_a' => ['sildenafil', 'viagra'], 'aliases_b' => ['isosorbide', 'isordil', 'monoket'], 'severity' => 'contraindicated', 'guidance' => 'Potential interaction between Sildenafil and Isosorbide detected; combined use is contraindicated.'],
        ];

        foreach ($interactions as $interaction) {
            DrugInteractionRule::updateOrCreate(
                ['medicine_a' => $interaction['medicine_a'], 'medicine_b' => $interaction['medicine_b']],
                ['aliases_a' => $interaction['aliases_a'], 'aliases_b' => $interaction['aliases_b'], 'severity' => $interaction['severity'], 'guidance' => $interaction['guidance'], 'is_approved' => false],
            );
        }

        $dosageRules = [
            ['medicine_name' => 'Paracetamol', 'aliases' => ['paracetamol', 'acetaminophen'], 'max_daily_mg' => 4000],
            ['medicine_name' => 'Ibuprofen', 'aliases' => ['ibuprofen'], 'max_daily_mg' => 2400],
            ['medicine_name' => 'Amoxicillin', 'aliases' => ['amoxicillin'], 'max_daily_mg' => 2000],
            ['medicine_name' => 'Aspirin', 'aliases' => ['aspirin'], 'max_daily_mg' => 4000],
            ['medicine_name' => 'Metformin', 'aliases' => ['metformin'], 'max_daily_mg' => 2550],
            ['medicine_name' => 'Warfarin', 'aliases' => ['warfarin'], 'max_daily_mg' => 10],
        ];

        foreach ($dosageRules as $rule) {
            DosageRule::updateOrCreate(
                ['medicine_name' => $rule['medicine_name']],
                ['aliases' => $rule['aliases'], 'max_daily_mg' => $rule['max_daily_mg'], 'is_approved' => false],
            );
        }
    }

    /**
     * Boilerplate WhatsApp/SMS/email templates used by the notification center.
     *
     * Every row is seeded with is_approved = false, mirroring the clinical
     * reference-data discipline: sending is blocked until an authenticated
     * reviewer approves the copy through the /notification-templates workflow
     * (DESIGN §15 "approval states"). The seeder must never approve on its own.
     */
    private function seedNotificationTemplates(): void
    {
        $templates = [
            [
                'channel' => NotificationTemplate::CHANNEL_EMAIL,
                'name' => 'Appointment confirmation',
                'subject' => 'Your appointment is confirmed',
                'body' => "Dear {{patient_name}},\n\nYour appointment with {{doctor_name}} is confirmed for {{appointment_date}} at {{appointment_time}} at {{clinic_name}}.\n\nPlease arrive 10 minutes early.",
            ],
            [
                'channel' => NotificationTemplate::CHANNEL_SMS,
                'name' => 'Appointment reminder',
                'subject' => null,
                'body' => 'Reminder: {{patient_name}}, your appointment with {{doctor_name}} is on {{appointment_date}} at {{appointment_time}}.',
            ],
            [
                'channel' => NotificationTemplate::CHANNEL_WHATSAPP,
                'name' => 'Lab result ready',
                'subject' => null,
                'body' => 'Hello {{patient_name}}, your {{test_name}} result is ready. Please contact {{clinic_name}} to collect it.',
            ],
            [
                'channel' => NotificationTemplate::CHANNEL_SMS,
                'name' => 'Follow-up reminder',
                'subject' => null,
                'body' => 'Follow-up reminder for {{patient_name}}: please book your visit at {{clinic_name}} on or before {{followup_date}}.',
            ],
            [
                'channel' => NotificationTemplate::CHANNEL_EMAIL,
                'name' => 'Invoice payment receipt',
                'subject' => 'Payment received — Invoice {{invoice_number}}',
                'body' => "Dear {{patient_name}},\n\nWe have received your payment of {{amount}} for invoice {{invoice_number}}. Thank you for choosing {{clinic_name}}.",
            ],
        ];

        foreach ($templates as $template) {
            NotificationTemplate::updateOrCreate(
                ['channel' => $template['channel'], 'name' => $template['name']],
                $template + ['is_approved' => false],
            );
        }
    }

    /**
     * The demo doctor account referenced by seeded appointments/prescriptions.
     */
    private function doctorUser(): User
    {
        return User::where('email', 'doctor@clinic.com')->firstOrFail();
    }

    /**
     * Create or refresh a user account, keeping the operation idempotent so
     * production-style "db:seed --force" runs never duplicate rows.
     */
    private function upsertUser(string $name, string $email, string $role): void
    {
        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'email_verified_at' => now(),
                'role' => $role,
            ],
        );
    }

    /**
     * Demo insurance carriers for claim workflows.
     */
    private function seedInsuranceProviders(): void
    {
        $providers = [
            ['code' => 'BCBS', 'name' => 'BlueCross BlueShield', 'contact_email' => 'claims@bcbs.example.com', 'phone' => '+1 (800) 555-0101'],
            ['code' => 'AETNA', 'name' => 'Aetna', 'contact_email' => 'provider@aetna.example.com', 'phone' => '+1 (800) 555-0102'],
            ['code' => 'CIGNA', 'name' => 'Cigna Health', 'contact_email' => 'claims@cigna.example.com', 'phone' => '+1 (800) 555-0103'],
        ];

        foreach ($providers as $provider) {
            InsuranceProvider::updateOrCreate(
                ['code' => $provider['code']],
                ['is_active' => true] + $provider,
            );
        }
    }
}
