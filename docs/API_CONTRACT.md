# API Contract — Clinic Management System

**Version:** 1.0 (Phase-0 repository audit)
**Base URL:** `/api/v1`
**Auth:** Session-based (cookie) via Laravel `web` guard + Sanctum
**Format:** JSON only. Accept header `application/json` is set by the SPA client.
**Future mobile:** React Native uses the same endpoints (token/session swaps are
transport-level; the contract is identical).

---

## 1. Authentication flow (SPA)

The SPA is **session-based**, not bearer-token:

| Step | Method | Path | Notes |
|---|---|---|---|
| 0 | `GET` | `/api/v1/csrf-cookie` | Regenerates session; sets `XSRF-TOKEN` cookie (unauthenticated, throttled `auth`) |
| 1 | `POST` | `/api/v1/login` | Body: `email`, `password`, optional `remember`. On success returns `{token, user}`; if 2FA required returns `{two_factor_required: true}` and stores `pending_two_factor_id` in session |
| 2 (if 2FA) | `POST` | `/api/v1/auth/2fa/verify` | Body: `code` (TOTP or recovery). Returns `{token, user}` |
| 3 | `GET` | `/api/v1/me` | Restore session on page reload → `{user}` |
| 4 | `POST` | `/api/v1/logout` | Invalidates session + CSRF token |

**Client requirements:**
- All stateful requests must send the `X-XSRF-TOKEN` header (value from the
  `XSRF-TOKEN` cookie) — axios `withCredentials` handling.
- `2fa` middleware: Doctor/Admin accounts that completed 2FA setup must verify
  a TOTP code **per session** before sensitive routes respond (403
  `{two_factor_required: true}` otherwise).
- The `user` payload = `{id, name, email, role, permissions[], branch_id,
  is_cross_branch, two_factor_enabled}`.

## 2. Rate limits (per IP or per user)

| Limiter | Applied to | Limit |
|---|---|---|
| `api` | all `/api` routes (default throttle) | 60/min |
| `auth` | login, csrf-cookie, 2FA verify | 5/min |
| `sensitive` | finalize/void/amend, merge, enable-2FA | 10/min |
| `public` | public site reads | 60/min |
| `public-submit` | booking / contact submits | 5/min |

## 3. Response envelope

The PRD (§14) specifies:
```json
{"success":true,"message":"...","data":{},"meta":{"request_id":"..."}}
```

**Standardized (G2 closed, 2026-09-24).** Every `/api/v1` response — success
and error path alike — is guaranteed to carry the envelope
`{success, message, data, meta:{request_id}}`, and the `X-Request-Id` response
header always matches `meta.request_id` so one id correlates a request
end-to-end:

- `App\Support\ApiEnvelope` is the single shape builder. Controllers reach it
  through the `ApiResponds` trait on the base `Controller` (`ok` / `created` /
  `error`); the exception handler calls it directly.
- Exceptions (401 / 403 / 404 / 405 / 419 / 422 / 429 / 500) render through the
  `withExceptions()` closure in `bootstrap/app.php` into the same envelope;
  422 keeps the top-level `errors` mapping for form display.
- `EnsureApiEnvelope` (appended to the `api` group) additively defaults any
  missing `success` / `message` / `data` / `meta` on controller-returned JSON.
  Keys are never moved or removed: auth/state endpoints still carry their flat
  payloads (`{token, user}`, `{two_factor_required}`, `{message}`) alongside
  the envelope keys, and pagination keeps its own `meta` (merged with
  `request_id`).
- Proven by `tests/Feature/ApiEnvelopeTest.php` (health, patients index,
  validation 422, permission 403, missing-resource 404).

## 4. Error format

| Status | Shape | Notes |
|---|---|---|
| 401 | `{success:false, message, data:null, meta:{request_id}}` | session expired |
| 403 | `{success:false, message, data:null, meta:{request_id}, two_factor_required?}` | permission denied (otherwise 404-style) or 2FA challenge needed |
| 422 | `{success:false, message, data:null, errors:{field:[...]}, meta:{request_id}}` | validation failed; `errors` kept for form display (`assertJsonValidationErrors` compatible) |
| 429 | `{success:false, message, data:null, meta:{request_id}}` | throttled — `Retry-After` set |
| 500 | `{success:false, message, data:null, meta:{request_id}}` | generic server error — internals never leaked in `message` |

Authorization is enforced server-side via the `permission:` middleware alias
(`EnsurePermission`) plus policies (backups, settings, data-export, CDSS).
A hidden UI control is never a security control (PRD §14).

---

## 5. Endpoint inventory

Legend — `🔐` = any authenticated+2FA user; permission names shown where applied.

### Public website (no auth)
| Method | Path | Description |
|---|---|---|
| GET | `/public/clinic/info` | Clinic identity (from active branches) |
| GET | `/public/services` | Marketing services list (config-driven) |
| GET | `/public/doctors` | Doctor cards (name/specialty only) |
| POST | `/public/booking` | Booking request (throttled 5/min) |
| POST | `/public/contact` | Contact form submit (throttled 5/min) |
| GET | `/health` | Health check (public) |
| GET | `/metrics` | Prometheus metrics |

### Auth / 2FA
| Method | Path | Permission |
|---|---|---|
| GET | `/me` | 🔐 |
| POST | `/logout` | 🔐 |
| POST | `/auth/2fa/enable` | 🔐 |
| POST | `/auth/2fa/confirm` | 🔐 |
| POST | `/auth/2fa/disable` | 🔐 |

### Branches / context
| Method | Path | Permission |
|---|---|---|
| GET | `/branches` | 🔐 |
| GET | `/branches/{branch}/staff` | 🔐 |
| POST / PUT / DELETE | `/branches` `/branches/{branch}` | `branches.manage` |

### Patients + MRN + duplicates
| Method | Path | Permission |
|---|---|---|
| GET | `/patients` | `patients.view` (search, page, per_page) |
| POST | `/patients` | `patients.manage` (returns `possible_duplicates`) |
| GET | `/patients/{patient}` | `patients.view` |
| PUT | `/patients/{patient}` | `patients.manage` |
| DELETE | `/patients/{patient}` | `patients.manage` |
| POST | `/patients/merge` | `patients.manage` + `sensitive` |

### Appointments + availability + queue
| Method | Path | Permission |
|---|---|---|
| GET / POST | `/appointments` | see/list + `appointments.manage` |
| PUT | `/appointments/{appointment}` | `appointments.manage` (validated status transitions) |
| GET | `/doctors`, `/doctors/{doctor}/availability` | `appointments.view` |
| GET/POST | `/doctors/{doctor}/schedules` (+ PUT/DELETE `/schedules/{schedule}`) | view / `appointments.manage` |
| GET/POST | `/doctors/{doctor}/time-offs` (+ PUT/DELETE `/time-offs/{timeOff}`) | view / `appointments.manage` |
| GET | `/queue` | `appointments.view` |
| POST/PUT | `/queue`, `/queue/{queueEntry}` | `appointments.manage` |

### Encounters / EMR / vitals / diagnoses
| Method | Path | Permission |
|---|---|---|
| GET/POST | `/encounters` | `encounters.view` / `encounters.manage` |
| GET | `/encounters/{encounter}` | `encounters.view` |
| POST | `/encounters/{encounter}/finalize` | `encounters.manage` + `sensitive` |
| POST | `/encounters/{encounter}/void` | `encounters.manage` + `sensitive` |
| POST | `/encounters/{encounter}/amend` | `encounters.manage` + `sensitive` |
| POST | `/encounters/{encounter}/vitals` | `encounters.manage` |
| POST | `/encounters/{encounter}/diagnoses` | `encounters.manage` |

### Medical history + medication reconciliation
| Method | Path | Permission |
|---|---|---|
| GET/POST | `/patients/{patient}/medical-history` (+ PUT `/medical-history/{medicalHistory}`) | `encounters.view` / `encounters.manage` |
| GET/POST | `/patients/{patient}/medication-reconciliations` | `encounters.view` / `encounters.manage` |
| PUT | `/medication-reconciliations/{medicationReconciliation}` | `encounters.manage` |
| POST | `/medication-reconciliations/{medicationReconciliation}/deactivate` | `encounters.manage` |
| POST | `/encounters/{encounter}/medication-reconciliations/reconcile` | `encounters.manage` |

Only `medicine_name` is required on write. `dosage` and `frequency` are
**nullable** and match the nullable schema columns: patients routinely cannot
state a dose, and demanding one pushes clinicians to record a figure they do
not know, which is worse than an explicit `null`. Send `null`, never `""` — a
blank or whitespace-only value is normalised to `null` server-side. An unknown
dose does not suppress the allergy or drug-interaction checks, which run off
`medicine_name`; only the dosage-range check declines to evaluate it.

#### Optimistic concurrency (409)

`medication_reconciliations` carries a `version` integer, exposed on every
read. Send it back on `PUT` and on `/deactivate` as a precondition:

```json
{"dosage": "850mg", "version": 3}
```

If the row has moved on, the write is **refused with `409 Conflict`** and
nothing is written — not partially, not over the other clinician's change. The
body uses the standard error envelope, with the conflict detail in `data`:

```json
{
  "success": false,
  "message": "This medication entry was changed by someone else while you were editing it. Reload and try again.",
  "data": {
    "resource": "medication_reconciliation",
    "id": 42,
    "expected_version": 3,
    "current_version": 4,
    "changed_by": "Dr Hina",
    "changed_at": "2026-10-02T09:05:00+00:00"
  },
  "meta": {"request_id": "..."}
}
```

A `409` is **not** a validation error and must not be rendered as one: the
payload was valid, the row simply moved. Show "changed by {changed_by}, reload
and try again" and refetch — do not show field errors, and do not retry the
write unchanged, because it will be refused again.

`version` is optional for backward compatibility. Omitting it still applies the
write (last-write-wins) so an older client keeps working, but the server logs a
warning. New clients must always send it.

Refused writes are audited as `medication_reconciliation.update_conflict` /
`.deactivate_conflict`, so a repeatedly-losing client is detectable.

### Prescriptions (+ finalize/void/amend)
| Method | Path | Permission |
|---|---|---|
| GET/POST | `/prescriptions` | `prescriptions.view` / `prescriptions.manage` |
| GET | `/prescriptions/{prescription}/pdf` | `prescriptions.view` |
| POST | `/prescriptions/{prescription}/finalize` | `prescriptions.manage` + `sensitive` |
| POST | `/prescriptions/{prescription}/void` | `prescriptions.manage` + `sensitive` |
| POST | `/prescriptions/{prescription}/amend` | `prescriptions.manage` + `sensitive` |

### Laboratory + verification
| Method | Path | Permission |
|---|---|---|
| GET/POST | `/lab-orders` | `prescriptions.view` / `prescriptions.manage` |
| PUT | `/lab-orders/{labOrder}` | `prescriptions.manage` |
| POST | `/lab-orders/{labOrder}/verify` | `lab.verify` (second-person verification) |
| POST | `/lab-orders/{labOrder}/amend` | `lab.verify` |

### CDSS (clinical decision support)
| Method | Path | Permission |
|---|---|---|
| GET | `/cdss/alerts` | `cdss.view` |
| POST | `/patients/{patient}/cdss/evaluate` | `cdss.manage` |
| PATCH | `/cdss/alerts/{alert}` (acknowledge) | `cdss.manage` |

### Pharmacy / Inventory
| Method | Path | Permission |
|---|---|---|
| GET | `/inventory` | `pharmacy.view` |
| GET | `/inventory/transactions` | `pharmacy.view` |
| POST | `/inventory/transactions` | `pharmacy.manage` (ledger-authoritative; no direct stock edit) |
| POST | `/dispensings` | `pharmacy.manage` (FEFO, expired blocked, concurrency-safe) |
| GET/POST | `/suppliers` (+ show/update/destroy) | `suppliers.view` / `suppliers.manage` |
| GET/POST | `/purchases` (+ show/update/destroy) | `purchases.view` / `purchases.manage` |
| POST | `/purchases/{purchase}/receive` | `purchases.manage` (Stock IN → batches) |

### Billing / Finance
| Method | Path | Permission |
|---|---|---|
| GET/POST | `/invoices` | `billing.view` / `billing.manage` |
| POST | `/invoices/{invoice}/payments` | `billing.manage` (full/partial) |
| POST | `/invoices/{invoice}/jazzcash/initiate` | `billing.manage` (returns signed `redirect_url` + `fields`; 503 when gateway unconfigured, 422 when amount > balance) |
| POST | `/jazzcash/callback` | **Public** (no auth, CSRF-exempt) — JazzCash posts the hosted-checkout result; **HMAC-SHA256 signature verified before any state change**; `000` → Payment (`method=jazzcash`) + invoice update; idempotent; HTML bounce back to SPA with `?jazzcash=success\|failed` |
| GET | `/invoices/{invoice}/pdf` | `billing.view` |
| POST | `/invoices/{invoice}/finalize` | `billing.manage` |
| POST | `/invoices/{invoice}/refunds` | `billing.manage` (linked reversal) |
| GET/POST | `/expense-categories` (+ update/destroy) | `expense_categories.view` / `.manage` |
| GET/POST | `/expenses` (+ show/update/delete) | `expenses.view` / `expenses.manage` |
| POST | `/expenses/{expense}/approve` | `expenses.manage` |

### Insurance / claims
| Method | Path | Permission |
|---|---|---|
| GET/POST | `/claims` (+ show/update/destroy) | `claims.view` / `claims.manage` |
| PATCH | `/claims/{claim}/status` | `claims.manage` |

### CRM / leads / communications
| Method | Path | Permission |
|---|---|---|
| GET/POST | `/leads` | `crm.view` / `crm.manage` |
| GET | `/leads/options` | `crm.view` |
| GET/PUT/DELETE | `/leads/{lead}` | view / `crm.manage` |
| PATCH | `/leads/{lead}/status` (transition) | `crm.manage` (enum-validated pipeline) |
| POST | `/leads/{lead}/communications` | `crm.manage` |

### Follow-ups
| Method | Path | Permission |
|---|---|---|
| GET/POST | `/followups` | `followups.view` / `followups.manage` |
| PUT | `/followups/{followup}` | `followups.manage` |
| POST | `/followups/{followup}/complete` / `/cancel` | `followups.manage` |

### Reports / Analytics
| Method | Path | Permission (all `reports.view`) |
|---|---|---|
| GET | `/reports/financial`, `/reports/financial/export` | |
| GET | `/reports/visits`, `/reports/lab-tests`, `/reports/low-stock` | |
| GET | `/reports/inventory/export` | |
| GET | `/reports/analytics/revenue`, `/utilization`, `/funnel`, `/branch-volumes` | |

`GET /reports/financial` and `GET /reports/analytics/revenue` additionally return
`meta.currency` (the ISO 4217 code from `CLINIC_CURRENCY`, default `PKR`) so no
client hardcodes a currency symbol. `/reports/financial` also returns
`meta.invoiced_today` (sum of invoice totals issued today) and
`meta.invoiced_today_date`. `invoiced_today` is deliberately **not** the same
figure as the "Revenue Today" metric: invoiced is billed, revenue is collected.

#### Operational reports (DESIGN §16)

The three ranged reports accept `from` / `to` as `Y-m-d` and reject a reversed
range with 422. Each returns `null` (not `0`) for a duration with no
measurements, and always reports the `sample_size` behind every average, so a
thin period cannot be read as a fast one.

| Method | Path | Description |
|---|---|---|
| GET | `/reports/analytics/lab-turnaround` | `ordered → sample → result → verified`, with `sample_size` / `average_minutes` / `p50` / `p90` / `p95` / `max` for each milestone, overall and per `test_name`. An order that has not reached a milestone is excluded from that milestone rather than counted as a very long wait. |
| GET | `/reports/analytics/wait-times` | Queue `checked_in_at → encounter created_at`, overall and grouped `by_doctor` / `by_branch` / `by_day`. Also returns `excluded_negative_rows`: rows where the encounter predates the check-in are dropped, not averaged in as negative waits. |
| GET | `/reports/analytics/lead-conversion` | Leads → booked appointment → completed visit, with `by_source` and `by_status`. A lead is matched to the patient register on phone (or name when no phone is on file) and only counts appointments created **after** the lead, so a returning patient is not scored as a conversion. |
| GET | `/reports/expiry` | Point-in-time batch exposure. Query `as_of` (default today) and `within_days` (default 90, max 365). Returns `expired` / `expiring` / `no_expiry_date` buckets, per-bucket `stock_value` in `currency`, and `has_open_alert` where the nightly scan has already raised a `stock_alerts` row. Scoped to the active branch via the owning inventory item. |
| GET | `/reports/expiry/export` | Same filters; streams CSV (`batch_number,item_name,item_sku,current_quantity,expiry_date,days_until_expiry,stock_value,currency,bucket`). |

`no_expiry_date` exists because `inventory_batches.expiry_date` is nullable: a
batch of equipment or non-perishables has no date. Those rows are reported
rather than omitted, so a clinic can chase the missing paperwork instead of
silently losing stock visibility.

### Documents
| Method | Path | Permission |
|---|---|---|
| GET | `/patients/{patient}/documents` | `documents.view` |
| POST | `/patients/{patient}/documents` | `documents.manage` (validated upload) |
| GET | `/documents/{document}/download` | `documents.view` |

### Patient portal (patient user, `portal.view`)
| Method | Path | Description |
|---|---|---|
| GET | `/portal/appointments` | patient's own appointments |
| POST | `/portal/appointments` | request appointment |
| GET | `/portal/invoices` (+ `/pdf`) | own invoices |
| GET | `/portal/prescriptions` (+ `/pdf`) | own prescriptions |
| GET/PUT | `/portal/consent` | view/update consent (`portal.manage`) |
| GET/POST | `/portal/documents` (+ download) | own documents |

### AI content (non-clinical)
| Method | Path | Permission |
|---|---|---|
| GET/POST | `/ai-content/drafts` (+ show/update/destroy) | `ai_content.view` / `.manage` |
| POST | `/ai-content/drafts/{draft}/submit` | `ai_content.manage` |
| POST | `/ai-content/drafts/{draft}/review` | `ai_content.manage` |
| POST | `/ai-content/drafts/{draft}/publish` | `ai_content.manage` (explicit human action — never auto-publishes) |

### Compliance / privacy (admin via policy)
| Method | Path |
|---|---|
| POST | `/compliance/export` |
| GET | `/compliance/exports` (+ `/exports/{export}` download) |
| POST | `/compliance/patients/{patient}/anonymize` |

### System / backup / settings (admin via policy)
| Method | Path |
|---|---|
| GET/POST | `/backups`, `/backups/{backup}/download`, DELETE `/backups/{backup}` |
| POST | `/backups/warm-cache` |
| GET/POST/PUT/DELETE | `/settings` CRUD |

### Telehealth (Reverb)
| Method | Path |
|---|---|
| GET/POST | `/telehealth/sessions` |
| POST | `/telehealth/sessions/{session}/messages` |
| PATCH | `/telehealth/sessions/{session}/end` |

### Notifications (self-service, `notifications.view`)
- `GET /notifications` — authenticated user's own log, newest first. Envelope items: `id, message (=title or body), channel, read, status, action_url (null), created_at`.
- `GET /notifications/channels` — `email|sms|whatsapp` with `enabled` (from `user.notification_preferences`) and `last_sent`.
- `PUT /notifications/{notification}/read` — mark own notification read; 403 if it belongs to another user.
- `PUT /notifications/channels/{channel}` (`notifications.manage`) — body `{enabled: bool}`; 422 for unknown channel.

### Notification templates (PRD §4 module 12, DESIGN §15; `notifications.view` / `notifications.manage`)
Reusable SMS/email/WhatsApp message templates with a human approval workflow.
Templates are created as drafts (`is_approved=false`) and only become sendable
after an actor with `notifications.manage` approves them; the seeder never
auto-approves (mirrors clinical reference data).

- `GET /notification-templates` (`notifications.view`) — paginated list (`page`, `per_page` ≤ 100); filters `channel` (`email|sms|whatsapp`) and `status` (`pending|approved`). Items: `id, channel, name, subject, body, is_approved, approved_by, approved_at, created_by, created_at`.
- `POST /notification-templates` (`notifications.manage`) — create draft. Body: `name` (string, unique per channel), `channel` (`in: email,sms,whatsapp`), `body` (required, ≤ 5000), `subject` (nullable, ≤ 255, email only). Audit: `notification_template.created`. Response 201.
- `GET /notification-templates/{template}` (`notifications.view`) — single template (same item shape).
- `PUT /notification-templates/{template}` (`notifications.manage`) — update fields (same validation). Editing an **approved** template resets it to pending (`is_approved=false`, clears `approved_by`/`approved_at`) for re-review. Audit: `notification_template.updated`.
- `DELETE /notification-templates/{template}` (`notifications.manage`) — soft-delete; **approved templates → 422** ("reject first"). Audit: `notification_template.deleted`.
- `POST /notification-templates/preview` (`notifications.view`) — render a draft body inline with sample/local data, **never sends**. Body: `channel`, `body`, optional `subject` + `data` map; response `{subject, body}` with `{{token}}` placeholders replaced (unknown tokens left intact).
- `POST /notification-templates/{template}/preview` (`notifications.view`) — render a stored template with sample data (no send).
- `POST /notification-templates/{template}/approve` (`notifications.manage`) — set `is_approved=true` + `approved_by`/`approved_at` (actor). Audit: `notification_template.approved`.
- `POST /notification-templates/{template}/reject` (`notifications.manage`) — reset to pending, clear approver identity. Audit: `notification_template.rejected`.

`NotificationService::sendFromTemplate($user, $template, $data)` blocks unapproved
templates with a validation error and records `template_id` on the resulting
`notification_logs` row when the drivers are disabled (logged fallback).

### Security / audit events (`audit.view`; Admin `*` bypasses)
- `GET /security-events` — paginated (`per_page` up to 100), filters `severity` (`info|warning|critical`) and `type` (LIKE). Items: `id, timestamp, severity, actor, ip_address, action (=type), details (=user_agent)`.

---

## 6. Conventions for new endpoints

- Thin controllers; logic in services/actions; Form Requests + Policies;
  transactions for multi-step writes; audit on state changes (PRD §2, §21).
- Resource routes are snake_case plural; sub-resources nest under the parent.
- Permission middleware alias `permission:{permission}`; add the permission to
  `permissions` table + role mapping rather than hard-coding role checks.
- List endpoints support `search`, `page`, `per_page` where applicable.