<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clinic Identity
    |--------------------------------------------------------------------------
    |
    | Branding used by printable documents (invoices, prescriptions). Values
    | can be overridden per-environment with the CLINIC_* variables.
    |
    */

    'name' => env('CLINIC_NAME', 'ClinicSuite Medical Center'),

    'address' => env('CLINIC_ADDRESS', '123 Wellness Street, Healthview, HV 10001'),

    'phone' => env('CLINIC_PHONE', '+1 (555) 123-4567'),

    'email' => env('CLINIC_EMAIL', 'billing@clinic.local'),

    'tax_rate' => (float) env('CLINIC_TAX_RATE', 13),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | ISO 4217 code the clinic bills in. Every monetary surface (reports,
    | invoices, exports, the staff UI) reads this so no screen can hardcode a
    | "$" symbol. The default matches the deployment country; override it with
    | CLINIC_CURRENCY rather than editing call sites.
    |
    */

    'currency' => env('CLINIC_CURRENCY', 'PKR'),

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | Default appointment length in minutes, used to partition the doctor's
    | working hours into bookable time slots.
    |
    */

    'appointment_duration_minutes' => (int) env('CLINIC_APPOINTMENT_DURATION_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Clinical Reference Data Readiness
    |--------------------------------------------------------------------------
    |
    | Prescription creation refuses to run when the clinical safety ruleset
    | has no approved rows, because an empty ruleset makes every prescription
    | pass silently (PRD §12/§22). This is fail-closed: the default of `null`
    | means "enforce whenever the app is in production", so there is no
    | configuration in which a production clinic quietly stops enforcing.
    |
    | Set to true/false only to exercise the behaviour in a non-production
    | environment, or to stage a deliberate, time-boxed rollout. This is
    | deliberately NOT a general-purpose safety bypass: the allergy,
    | interaction and dosage block in PrescriptionController is unaffected by
    | this key and cannot be disabled at all.
    |
    */

    'reference_data' => [
        'require_approved_before_clinical_writes' => env('CLINIC_REQUIRE_APPROVED_REFERENCE_DATA'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Website Marketing Content
    |--------------------------------------------------------------------------
    |
    | Non-clinical service showcase content used by the public website
    | (PRD Module 1). The clinic identity (name, address, phone) is sourced
    | from the active Branch records at request time; only marketing content
    | that has no clinical, financial or identity-safety impact lives here.
    |
    */

    'services' => [
        'General Consultation',
        'Pediatrics',
        'Maternal & Child Health',
        'Cardiology Consultation',
        'Dermatology',
        'Dental Care',
        'Eye Care',
        'Physiotherapy',
        'Diagnostic Laboratory',
        'In-Clinic Pharmacy',
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Failure Alerting
    |--------------------------------------------------------------------------
    |
    | Phone number (E.164, e.g. +15550009999) that receives the automated SMS
    | when the nightly `clinic:backup` fails. When this is blank, the first
    | Admin user's phone is used instead. The alert always exits through the
    | `sms` messaging driver (config/messaging.php: TWILIO_* credentials +
    | TWILIO_SMS_ENABLED=true); without it the attempt degrades to an audit
    | log + notification_logs row so a broken alert never masks the backup.
    |
    */

    'backup_alert_sms_to' => env('CLINIC_BACKUP_ALERT_SMS_TO'),

];
