# PRD — Clinic Management System
**Version:** 1.0 | **Status:** Phase-0 Ready  
**Stack:** Laravel + React + TypeScript + MySQL + Redis  
**Future mobile:** React Native via the same `/api/v1/` API

## 1. Purpose
Build a secure, production-oriented Pakistan clinic management system covering:
Registration → MRN → Appointment → Check-in → Queue → Doctor → EMR → Prescription/Lab → Pharmacy/Lab → Billing → Follow-up → Patient Portal → Audit.

This PRD is based on the approved Master Blueprint v4, which is ready to share with all four developers. fileciteturn5file0L2-L8

## 2. Mandatory architecture
React/TypeScript → HTTPS/rate limiting → Laravel `/api/v1/` → Auth/RBAC → Clinical/Business services → MySQL.
Redis handles cache/queue/sessions. Secure file/object storage handles documents. External integrations have no direct clinical DB access. AI is limited to approved non-clinical content and has no clinical-table access. fileciteturn5file0L50-L75

Every state-changing request follows:
**Authentication → Authorization/Policy → Validation → Service/Action → MySQL transaction → Audit → Response.**

Controllers must not directly write clinical/financial data.

## 3. Users
- Patient
- Receptionist
- Doctor
- Pharmacy staff
- Lab staff
- Accountant/Finance staff
- Admin

## 4. Main modules
1. Public clinic website
2. Auth/RBAC/2FA
3. Patients + MRN + duplicate detection
4. Appointments + queue
5. EMR/doctor consultation
6. Prescription safety
7. Laboratory
8. Pharmacy/inventory
9. Billing/finance
10. Patient portal
11. CRM/leads
12. Notifications/WhatsApp/SMS/email
13. Analytics/reports
14. Non-clinical AI content
15. Audit/security
16. Medical document storage
17. Future mobile API compatibility

## 5. Patient flow
```text
Registration/Search → MRN + duplicate check → Appointment
→ Confirmed → Arrival → Queue → Doctor/Encounter
→ Mandatory safety check → Vitals/History/Exam
→ Diagnosis → Prescription/Lab
→ Pharmacy and/or Lab → Billing/Payment
→ Follow-up/Reminder → Final record + Audit
```
This matches the approved v4 journey. fileciteturn5file0L76-L90

## 6. Appointment
Statuses:
`BOOKED → CONFIRMED → ARRIVED → WAITING → IN_CONSULTATION → COMPLETED`
and `CANCELLED` / `NO_SHOW` where valid.

Invalid transitions are rejected server-side and audited. Double booking must be prevented by application/transaction logic **and a MySQL database-level backstop**. fileciteturn5file0L92-L105

## 7. Clinical safety
Before clinical decision-making, show:
- allergies
- current medicines/medication reconciliation
- critical history

Prescription checks:
- allergy conflict
- drug-drug interaction
- dosage/range rule
- relevant expiry rule

Unsafe critical actions are blocked server-side. Overrides require authenticated actor + reason + audit. Finalized records are not unrestricted hard-deletable. Corrections use void/amend while preserving the original. Clinical rules require qualified-clinician validation. fileciteturn5file0L106-L126

## 8. Pharmacy
`Purchase → Batch → Stock IN → Eligible Batch → Dispense → Stock OUT → Invoice → Payment → Audit`

Rules:
- expired medicine blocked server-side
- `stock_movements` is authoritative
- batch quantity is derived, not directly edited
- concurrent dispensing uses transactions/row locking
- FEFO/expiry-aware selection
- auditable stock corrections fileciteturn5file0L128-L140

## 9. Lab
`Order → Sample → Processing → Result → Second-person verification → FINAL → Doctor/Patient access`

Verification and finalization must be auditable. fileciteturn5file0L134-L140

## 10. Finance
`Charge → Draft Invoice → Finalize → Payment(s) → Balance → Refund/Reversal → Reports`

Finalized invoices/payments are immutable. Corrections use linked reversal/refund records. fileciteturn5file0L137-L140

## 11. MySQL baseline
Core: `users, roles, permissions, clinics, doctors, staff, consents, audit_logs, security_events`

Clinical: `patients, allergens, patient_allergies, patient_medical_history, medication_reconciliations, doctor_schedules, doctor_time_offs, appointments, queue_entries, encounters, vitals, diagnoses, prescriptions, prescription_items, drug_interaction_rules, dosage_rules, followups, lab_orders, medical_documents`

> **Naming note (§11):** this list included a separate `lab_results` table. The implementation stores the result as a column on `lab_orders` (see `docs/ERD.md`); there is no separate `lab_results` table. The PRD specifies no requirement for multiple results per order, so a single result column per order is sufficient. Revisit if panels or multi-analyte results (several results per order) are needed.

> **Naming note (§11):** this table was originally listed as `doctor_leaves`. The implemented schema uses `doctor_time_offs` (see `docs/ERD.md` and the `doctor_time_offs` endpoints in `docs/API_CONTRACT.md`). The two names refer to the same entity — a doctor's recorded time off that blocks bookable slots. There is no separate `doctor_leaves` table and no approval lifecycle: time off is recorded directly and takes effect immediately. The UI copy was corrected to "Record time off" / "Blocks bookable slots" to match.

Pharmacy/Finance: `medicines, medicine_categories, medicine_batches, suppliers, purchases, purchase_items, stock_movements, dispensing, invoices, invoice_items, payments, refunds, expenses, expense_categories`

CRM/Communication: `leads, communications, notifications, ai_content_drafts`

Rules:
- Laravel migrations only; no manual production schema edits
- FKs/indexes
- unique/indexed MRN
- duplicate detection + controlled merge
- protect CNIC
- stock ledger authoritative
- approved DB constraint for active appointment slots
- transactions for multi-table operations
- consistent timestamps/timezone
- synthetic data only in dev/staging fileciteturn5file0L141-L160

## 12. Clinical reference data
Tables:
`allergens`, `dosage_rules`, `drug_interaction_rules`

The clinic's named qualified clinical lead owns the actual clinical content. Admin maintenance is audited; rules are not hardcoded. Initial data must be approved and loaded before clinical testing, and production cannot start with empty/placeholder safety data. fileciteturn5file0L162-L186

## 13. Localization
Recommended:
- public website: Urdu + English
- patient portal: Urdu + English
- notifications: Urdu support/default or patient preference
- internal dashboards: English acceptable for v1

If bilingual is approved, establish i18n early. fileciteturn5file0L187-L198

## 14. API
Base: `/api/v1/`

Required areas:
- patients
- appointments/status
- encounters
- prescriptions/void
- lab orders
- medicines/inventory
- invoices/payments

Common response:
```json
{"success":true,"message":"...","data":{},"meta":{"request_id":"..."}}
```

API authorization must be tested directly; hidden UI controls are never security. fileciteturn5file0L200-L217

## 15. Four developers
**Dev 1 — Backend/Core/MySQL/Security/DevOps**
Laravel, API, auth/2FA, RBAC, core schema/migrations, DB appointment constraint, audit/security, Redis, backups/restore, CI/CD, monitoring.

**Dev 2 — Clinical/Doctor/Patient**
Appointments, queue, EMR, medication reconciliation, vitals/history/exam, diagnosis, prescriptions/safety checks, reference-data admin UI, follow-up, lab clinical content, documents.

**Dev 3 — Pharmacy/Lab Verification/Finance**
Medicines, batches, FEFO, stock ledger/concurrency, dispensing, alerts, lab verification, invoices, payments, refunds/reversals, expenses/reports.

**Dev 4 — React/Portal/CRM/Communication**
React/TypeScript, public site, dashboards, patient portal, pharmacy/lab/finance UI, CRM, notifications, WhatsApp UI, analytics, AI review UI, i18n, accessibility, frontend tests.

These ownership boundaries and coordination rules are defined in v4. fileciteturn5file0L218-L237

## 16. GitHub
`main ← PR ← develop ← feature/*`

Rules:
- no direct main push
- PR required
- reviewer required
- clinical/security/payment PRs get extra review
- CI must pass
- no secrets/real patient data
- migrations reviewed
- Project Board used
- production only from reviewed/tagged main fileciteturn5file0L239-L255

## 17. Security
HTTPS, secure cookies, CSRF as appropriate, 2FA, rate limiting, least-privilege RBAC, audit logs, CNIC protection, secure document storage, file validation, malware scanning where available, externalized secrets, re-auth for sensitive finalization, encrypted backups, tested restore, monitoring, human alerting, synthetic dev/staging data. fileciteturn5file0L256-L270

## 18. Testing
Must cover:
- unit rules
- API authorization/validation
- appointment concurrency
- stock concurrency
- clinical integration
- finance/inventory reconciliation
- security/IDOR
- file upload abuse
- UAT with synthetic data
- CI/regression

Clinical-critical tests run on every PR. fileciteturn5file0L271-L281

## 19. Go-live gate
Do not deploy until every v4 gate passes: Phase-0 sign-off, clinic UAT, qualified clinician validation, approved reference data, Pakistani legal/privacy/regulatory review, backup/restore drill, security tests, DB+application double-booking tests, clinical integration tests, finance/inventory reconciliation, synthetic staging, monitoring, incident procedure, and localization decision. fileciteturn5file0L283-L299

## 20. Phase-0 decisions
Finalize:
WhatsApp provider, storage provider, clinic model, doctor finalization/signature, regulatory owner, RPO/RTO, retention, notifications/consent, payment methods, lab verification, AI scope, reference-data owner, localization scope. fileciteturn5file0L315-L329

## 21. Definition of Done
A feature is done only when:
- backend validation exists
- server authorization exists
- required DB constraints exist
- tests pass
- audit is implemented where required
- error handling exists
- API contract is documented
- UI is connected to the real API
- no secrets/real patient data are committed
- PR reviewed
- CI passes
- UAT completed where applicable

## 22. Team rule
Never guess about a clinical rule, destructive migration, authorization boundary, or architecture-changing requirement. Raise a GitHub decision/issue and get approval.
