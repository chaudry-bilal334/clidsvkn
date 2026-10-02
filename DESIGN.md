# DESIGN.md — Clinic Management System

**Version:** 1.0  
**Product:** Pakistan Clinic Management System  
**Frontend:** React + TypeScript  
**Backend:** Laravel API  
**Database:** MySQL  
**Cache/Queue:** Redis  
**Future Mobile:** React Native

## Purpose

This document defines the practical UI/UX design for the Clinic Management System across the public website, staff dashboards and patient portal.

## Source Hierarchy

- `PRD.md` / approved Blueprint controls functionality, security and clinical behavior.
- `DESIGN.md` controls visual/UI implementation.
- If UI design conflicts with safety/security, safety/security wins.

## 1. Design Principles

- Clinical first: important patient safety information is visible before actions that can affect care.
- Low cognitive load: each role sees what is needed for its current task.
- Clear states: every record has visible status, owner and next action.
- Safe actions: destructive/finalizing actions require confirmation and, where required, re-authentication.
- Audit awareness: finalized/immutable records visibly communicate that they cannot be edited normally.
- Responsive: desktop-first for staff, mobile-friendly patient portal/public website.
- Accessible: keyboard navigation, visible focus, sufficient contrast, readable labels and clear errors.

## 2. Visual System

Use a professional healthcare visual language: calm neutral surfaces, restrained primary accent, white/light cards, subtle borders, generous spacing and strong status indicators. Avoid gaming-style gradients, excessive animation and noisy dashboards.

| Token | Recommendation |
|---|---|
| Font | Inter or system sans-serif; use a font with strong Urdu glyph support when Urdu is enabled. |
| Base spacing | 4px scale; common spacing 8/12/16/24/32px. |
| Radius | 8–12px for cards/inputs; avoid excessive pill shapes. |
| Buttons | Primary, secondary, danger, ghost; comfortable touch targets. |
| Status | Use text + icon, not color alone: Booked, Waiting, In Consultation, Completed, Cancelled, No-show, Critical. |
| Tables | Sticky headers where useful, pagination, search/filter and clear row actions. |

## 3. Global Application Shell

Staff application:
- left sidebar navigation
- top bar
- content area
- permission-aware navigation
- clinic context
- global patient search
- notifications
- user/profile
- secure logout

Patient portal:
- simplified top navigation
- mobile bottom navigation where appropriate

Staff navigation:
`Dashboard • Patients • Appointments • Queue • Clinical/EMR • Lab • Pharmacy • Billing • CRM • Reports • Notifications • Settings • Audit`

## 4. Public Website

Pages:
- Home
- Services
- Doctors
- About/Clinic
- Contact
- Appointment Booking
- Patient Login
- Privacy/Consent

Hero section communicates clinic identity, services and booking CTA.

Doctor cards show name, specialty and schedule/availability only where approved.

Booking flow is short and step-based.

## 5. Login / Security UI

Login:
- clinic logo
- email/phone
- password
- remember/session messaging
- forgot password

Doctor/admin accounts show 2FA.

Sensitive finalization screens can request re-authentication.

Never display sensitive patient data on unauthenticated screens.

## 6. Role-Based Dashboards

### Reception
- today's appointments
- arrivals
- waiting queue
- no-shows
- quick patient search/register

### Doctor
- today's patients
- waiting patients
- critical safety alerts
- follow-ups

### Pharmacy
- pending dispensing
- low stock
- near expiry
- today's sales

### Lab
- pending samples
- processing
- awaiting verification
- finalized results

### Finance
- today's invoices
- payments
- outstanding balances
- refunds awaiting action

### Admin
- system health
- users
- audit/security alerts
- operational KPIs

## 7. Patient Registration / MRN

Use a two-column desktop form with sections:
- identity
- contact
- emergency contact
- consent
- relevant demographics

Show existing-patient matches before final creation.

Possible duplicate warning must be prominent and require review.

After creation show MRN in a success summary card.

## 8. Patient Profile / EMR

Header:
- patient name
- MRN
- age/sex where appropriate
- status
- safe contact actions

Persistent clinical safety banner:
- allergies
- current medication reconciliation
- critical history

Tabs:
`Overview • Encounters • Vitals • Diagnoses • Prescriptions • Labs • Documents • Follow-ups • Audit`

Clinical flow:
`open encounter → safety check → vitals/history/exam → assessment → prescription/lab → review → finalize`

Finalized records display a locked/final badge and amendment workflow instead of a normal Edit button.

## 9. Appointment & Queue

Appointment screen:
- calendar/list views
- doctor filter
- date
- status
- patient search

Booking clearly shows:
- doctor
- date/time
- current slot state

Conflict response is clear and does not expose implementation details.

Queue screen:
- token/position
- patient
- arrival time
- doctor
- wait duration
- status

## 10. Prescription UI

Prescription builder shows the patient safety panel before medication entry.

Each medication row:
- medicine
- strength
- dose
- route
- frequency
- duration
- quantity
- instructions

Safety results appear next to the relevant item.

A blocked critical conflict cannot be bypassed simply by closing a warning.

Approved override requires reason and identity.

Final review summarizes all medicines before re-auth/finalize.

## 11. Laboratory UI

Statuses:
`Ordered → Sample Collected → Processing → Result Entered → Awaiting Verification → Final`

Result entry separates patient/test context from result fields.

Verification clearly shows who entered the result and requires verifier confirmation.

Final results become locked with an amendment path.

## 12. Pharmacy / Inventory UI

Pharmacy dashboard:
- pending prescriptions
- dispensing queue
- low-stock alerts
- expiry alerts

Medicine page:
- batches
- expiry
- available quantity
- supplier

Dispensing screen:
- selected eligible batch
- quantity
- invoice impact

Expired batches appear visually disabled and never as valid dispense options.

## 13. Billing / Finance UI

Invoice page:
- patient
- items
- subtotal
- discounts/taxes if approved
- total
- payments
- balance

Finalized invoice shows locked state.

Payment entry supports full/partial payment.

Refund/reversal is a separate action with reason and link to original transaction.

Do not rely on green/red alone for financial states.

## 14. Patient Portal

Mobile-friendly pages:
`Home • Appointments • Prescriptions Summary • Lab Results • Documents • Notifications • Profile/Consent`

Urdu/English toggle if bilingual scope is approved.

Never expose:
- staff-only notes
- audit/security details
- unauthorized records

## 15. CRM / Communications

CRM stages:
`New → Contacted → Qualified → Appointment/Converted → Closed/Lost`

Communication timeline:
- channel
- timestamp
- status
- responsible user

WhatsApp/SMS/email templates:
- preview
- approval states

AI-generated content must be visibly marked as draft and require human approval before publishing.

## 16. Reports / Analytics

Use filters first, then summary cards/charts/tables.

Examples:
- appointment volume
- wait times
- no-shows
- revenue
- outstanding balances
- pharmacy stock/expiry
- lab turnaround
- lead conversion

Restrict clinical reports by role and avoid unnecessary sensitive identifiers.

## 17. Empty / Loading / Error States

Every major screen must have:
- loading skeleton/spinner
- empty state with next action
- field-level validation errors
- permission-denied state
- server-error state with request ID when available
- safe retry

Never show a blank page for an error.

## 18. Mobile / Future App

Keep the React web design API-driven so React Native can reuse the same `/api/v1/` contracts.

Patient portal interactions should be touch-friendly.

Do not create web-only business rules that the future mobile app would need to duplicate.

## 19. Accessibility & Localization

Use:
- semantic labels
- keyboard support
- focus states
- readable error text
- status icons

Urdu/English UI uses translation keys, not hardcoded strings.

Support RTL for Urdu where appropriate.

Doctor-entered free text remains in the doctor's language; fixed UI labels/templates are translated.

## 20. Dangerous Actions

Explicit confirmation is required for:
- patient merge
- prescription finalization
- lab finalization
- invoice finalization
- refund/reversal
- stock adjustment
- record amendment

The UI explains restrictions, but backend enforcement remains authoritative.

## 21. Reusable Design System

OpenCode should implement reusable components:

`AppShell, Sidebar, Topbar, PageHeader, DataTable, SearchFilterBar, StatusBadge, StatCard, FormSection, Modal, ConfirmDialog, Toast/Alert, EmptyState, LoadingState, ErrorState, PatientSafetyBanner, Timeline, Stepper, QueueBoard, AppointmentCalendar, ClinicalRecordCard, PrescriptionBuilder, LabResultCard, InvoiceSummary, AuditTimeline`

## 22. UI Acceptance Checklist

Before accepting a feature:
- matches DESIGN.md
- responsive
- keyboard accessible
- loading/empty/error states present
- permission-aware
- no sensitive data leakage
- destructive/final actions confirmed
- API errors handled
- Urdu/English behavior correct where approved
- tests pass
- screenshots/PR review completed

## 23. Final Design Rule

Do not make this look like a generic SaaS admin template.

It should feel like a professional healthcare product:
- patient identity is always clear
- clinical safety information is prominent
- operational queues are easy to scan
- financial states are unambiguous
- finalized records are visibly protected
