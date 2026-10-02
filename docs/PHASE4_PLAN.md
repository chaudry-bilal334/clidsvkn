# Phase 4 Plan — Lab, Pharmacy, Inventory

**Status:** plan only (no Phase 4 feature code written yet)
**Source of truth:** ground-truth inventory of the actual repo (migrations, models, services, controllers, routes, frontend views, tests), not earlier reports that guessed at table names.

---

## 1. Ground-truth summary — what already exists

Most of Phase 4 is already implemented. The earlier "missing tables" guesses were wrong: the schema uses shared inventory tables rather than pharmacy-specific ones.

| Area | Exists | Notes |
|---|---|---|
| Lab orders | ✅ | `lab_orders` table, `LabOrder` model, `LabOrderController`, routes, `LabView`, tests |
| Lab verification | ✅ | `LabVerificationService` (second-person verify, amend, finalize), `verification_state` on `lab_orders` |
| Lab portal visibility | ✅ | `PortalController::labResults()` (verified-only), `PatientPortalView` lab-results tab |
| Lab results storage | ⚠️ | Stored as a `result` column on `lab_orders`, **not** a separate `lab_results` table (PRD §11 lists one) |
| Lab sample collection | ❌ | PRD §9 / DESIGN §11 specify `Order → Sample Collected → Processing → Result`; no samples table or sample-collected state exists |
| Medicines | ⚠️ | No `medicines` table — medicines are `inventory_items` with `category = 'medicine'` |
| Batches | ⚠️ | No `medicine_batches` table — shared `inventory_batches` table |
| Stock movements | ⚠️ | No `stock_movements` table — shared `inventory_transactions` table |
| Suppliers | ✅ | `suppliers` table, model, controller, routes, `SuppliersView`, tests |
| Dispensing + FEFO | ✅ | `dispensings` table, `DispensingService` (FEFO, atomic, row-locked), `DispensingController`, `PharmacyDispensingView`, tests |
| Purchases | ✅ | `purchases` + `purchase_items`, `PurchaseController`, `PurchaseService`, `PurchasesView`, tests |
| Inventory items/transactions | ✅ | `InventoryItem`, `InventoryBatch`, `InventoryTransaction`, `InventoryController`, `InventoryView`, tests |
| Expiry alerts | ❌ | No proactive expiry scanning/alerting (command, job, or service). Expiry is only checked at point-of-dispense via `availableBatches()` / `isAvailable()` |
| Dispense → Invoice | ❌ | PRD §8 flow is `…Dispense → Stock OUT → Invoice → Payment → Audit`; DESIGN §12 lists "invoice impact" on the dispensing screen. Dispensing currently does **not** generate an invoice |

---

## 2. Remaining work by area

### 2.1 Lab

**L1 — Sample collection step** *(missing)*
PRD §9 (`Order → Sample → Processing → Result → Second-person verification → FINAL`) and DESIGN §11 (`Ordered → Sample Collected → Processing → Result Entered → Awaiting Verification → Final`) both specify a sample-collection stage between order and result. Today the workflow goes Order → Result directly.
- Deliverables: `samples` migration + model (or a `sample_collected_at` column on `lab_orders` if a separate table is judged too heavy — **decision required**), a status transition, controller/route updates, frontend status display, tests.
- Required test: `lab order → sample collected → result → verify → final` end-to-end.

**L2 — `lab_results` table vs `result` column** *(decision required)*
PRD §11 lists `lab_results` as a separate table; the implementation stores `result` on `lab_orders`. This is a schema/contract mismatch. Either split results into their own table (migration + model + relation) or correct the PRD/ERD to match the column. **Do not assume — needs a decision.**

### 2.2 Pharmacy

**P1 — Dispense → Invoice integration** *(missing)*
PRD §8 specifies `Dispense → Stock OUT → Invoice → Payment → Audit`; DESIGN §12 lists "invoice impact" on the dispensing screen. `DispensingService` currently dispenses stock and closes the prescription but creates no invoice.
- Deliverables: invoice generation on dispense (a `BillingService` call or a new `DispenseInvoicingService`), invoice line items from the dispensed batches, controller/route wiring, frontend "invoice impact" display, tests.
- Required test: `prescription → dispense → stock → invoice` integration (stock decremented via FEFO, prescription closed, invoice created with correct line items and totals).

**P2 — Expiry alerts** *(missing)*
PRD §167 lists "alerts" for the pharmacy; DESIGN §12 disables expired batches at point-of-dispense (already works). What is missing is **proactive** alerting before batches expire.
- Deliverables: an expiry-scan command + scheduled task (or a queued job), an alert notification (reusing the existing `NotificationService` / `LowStockAlertTriggered` pattern), an admin/frontend alert surface, tests.
- Required test: expiring-soon batches raise an alert; expired batches are never dispensed (already covered).

### 2.3 Inventory

**I1 — Expiry alerts** *(shared with P2)*
Same deliverable as P2 — expiry alerting is a pharmacy/inventory concern. No inventory-specific gap beyond this; purchases, suppliers, items, batches, and transactions all exist and are tested.

---

## 3. Required tests — current status

| Required test | Status | Evidence |
|---|---|---|
| FEFO correctness | ✅ exists | `ClinicalSafetyAndConcurrencyTest` (earliest-expiry-first), `PrdGapClosureTest` (FEFO + expired-batch block + unfulfillable dispense) |
| Last-unit dispensing race | ✅ exists | `ConcurrencyRaceTest` ("a parallel last-unit dispense race never drives stock below zero"), `ClinicalSafetyAndConcurrencyTest` (row-locked dispense) |
| Concurrent stock movements | ✅ exists | `ConcurrencyRaceTest` (held write lock → 409), `withTransactionRetry` in `InventoryController` |
| Prescription → dispense → stock → invoice | ❌ **missing** | Dispense→stock exists; the **invoice leg does not exist** (P1) |
| Lab order → result → verify → final | ✅ exists | `LabResultSafetyTest`, `PrdGapClosureTest` (verify/amend/finalize) — but the **sample-collected** state is missing (L1) |

---

## 4. Suggested PR split (3 PRs, each Phase 4 scope)

**PR 1 — Lab sample collection (L1) + lab_results decision (L2)**
- Add the sample-collection stage to the lab workflow (migration/model or column — decision), status transition, controller/route, frontend status display.
- Resolve the `lab_results` table-vs-column question.
- Tests: `lab order → sample collected → result → verify → final`.

**PR 2 — Dispense → Invoice integration (P1)**
- Generate an invoice on dispense with line items from dispensed batches.
- Wire controller/route + frontend "invoice impact".
- Tests: `prescription → dispense → stock → invoice` integration.

**PR 3 — Expiry alerts (P2/I1)**
- Expiry-scan command + scheduled task + alert notification + admin/frontend surface.
- Tests: expiring-soon raises an alert; expired never dispensed.

---

## 5. Health-check finding (Task 1)

The Laravel health endpoint at `api/v1/health` now returns **503** when the safety ruleset is unready in production. Consumption audit:

| Consumer | Path | Hits Laravel health? | Affected by ruleset? |
|---|---|---|---|
| Dockerfile | — | No `HEALTHCHECK` instruction | No |
| docker-compose `app` | — | No healthcheck defined | No |
| CI e2e job | `http://localhost/up` | No — nginx has no `/up` location, serves SPA fallback (200) | No |
| k8s php-fpm livenessProbe | `php artisan app:health-check` | No — **command does not exist** (pre-existing gap) | No |
| k8s php-fpm readinessProbe | `/php-fpm/ready` :9000 | No — php-fpm is FastCGI, not HTTP | No |
| k8s nginx readinessProbe | `/health` :80 | No — nginx serves SPA fallback (200), not Laravel | No |
| ingress | `/health` → nginx | No — same as above | No |
| monitoring | — | No health-endpoint references | No |

**Conclusion:** an unseeded first deploy will **not** cause restart loops or a failed rollout — nothing in the current deployment configuration consumes `api/v1/health`. The endpoint is operator-facing only.

**Recommendation (not implemented — no active consumer):** if a probe is ever wired to `api/v1/health`, separate liveness from readiness so the ruleset check does not drive a restart. Concretely: keep `clinical_reference_data` as a reported check but do not let it flip the overall status to `unhealthy`/503, or move it to a dedicated readiness endpoint. This is forward-looking hardening; with no consumer it is not a live bug, so no code change was made.

**Pre-existing deploy gap found (unrelated to the ruleset):** `deploy/deployment.yaml` livenessProbe runs `php artisan app:health-check`, but no such command exists in the codebase. This probe would fail regardless of the ruleset. Flagged for the owner; not fixed here (out of scope, and fixing it is not a Phase 4 deliverable).
