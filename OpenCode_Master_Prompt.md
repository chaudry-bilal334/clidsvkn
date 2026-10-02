# OpenCode Master Prompt — Clinic Management System

You are the primary coding agent for this repository.

## Read these first
1. `PRD.md` — functional and engineering source of truth.
2. `DESIGN.md` — UI/UX source of truth when the project owner supplies it.
3. The approved Master Blueprint v4 PDF if it is present.

Do not start major implementation until you have inspected the repository and these documents.

## Mission
Build a maintainable, secure, production-oriented clinic management system using:
- Laravel
- React + TypeScript
- MySQL
- Redis
- REST API `/api/v1/`
- future React Native client using the same API

## Non-negotiable safety
Never sacrifice clinical safety, authorization, data integrity, auditability or security for speed.

Never invent clinical rules.
Never put critical clinical/finance/inventory enforcement only in React.
Never silently change the PRD.
If requirements conflict, create a decision/issue and stop that part until approved.

## Architecture
Every state-changing request:
Authentication → Authorization/Policy → Validation → Service/Action → MySQL transaction where required → Audit → Response.

Controllers stay thin.
Business logic belongs in services/actions/domain logic.
Use Form Requests/validation, Policies, transactions and consistent API errors.

## Clinical
Implement server-side:
- allergy checks
- drug interaction checks
- dosage/range checks
- relevant expiry checks

Critical unsafe actions must be blocked.
Override requires authenticated actor, reason and audit.
Finalized clinical records are not freely deleted.
Corrections use void/amend preserving originals.

## Appointment
Implement the approved state machine.
Prevent double booking at both application/transaction level and MySQL database level.
Test concurrent booking attempts.

## Pharmacy
`stock_movements` is authoritative.
Do not expose direct normal stock-quantity editing.
Expired medicine must be blocked server-side.
Use transaction/row locking for concurrent dispensing.
Test the last-unit race condition.

## Finance
Finalized invoices/payments are immutable.
Use refunds/reversals linked to originals.
Never silently rewrite historical financial records.

## Lab
Order → Sample → Processing → Result → Verification → Final.
Record verifier identity/time.
Protect finalized results.

## Security
Enforce server-side RBAC/policies.
Protect CNIC and medical documents.
Use secure file handling.
Never commit `.env`, secrets, tokens, API keys or real patient data.
Use 2FA for doctor/admin as specified.
Use rate limiting.
Use audit/security events.
Use encrypted backups and actually test restore.

## Database
Use Laravel migrations only.
Use FKs, indexes, unique constraints and transactions.
Do not manually modify production schema.
Do not create contradictory duplicate tables.
Keep the approved v4 schema baseline.

## API
Use `/api/v1/`.
Do not invent incompatible endpoint shapes.
Keep response/error formats consistent.
Future React Native must use the same API.

## UI
Do not invent a random design.
When `DESIGN.md` is supplied, follow it for:
- layout
- navigation
- colors
- typography
- spacing
- components
- responsive behavior
- loading/empty/error states

PRD controls functionality/security. DESIGN controls visual implementation. If they conflict, flag it instead of silently changing requirements.

## GitHub
Use:
`main ← PR ← develop ← feature/*`

Never push directly to main.
PR review required.
Clinical/security/payment changes require the specified extra review.
CI must pass before merge.
Never commit secrets or real patient data.

## Four developer boundaries
Dev 1: Backend/MySQL/Security/DevOps/API
Dev 2: Clinical/Doctor/Patient/EMR/Prescription safety/reference data
Dev 3: Pharmacy/Inventory/Lab verification/Finance
Dev 4: React/Portal/CRM/Communication/UX/i18n

Respect ownership and coordinate through the shared API and schema.

## Development process
Before coding:
1. Inspect all repository files.
2. Read PRD.md.
3. Read DESIGN.md if available.
4. Inspect current Laravel/React structure.
5. Inspect routes, migrations, models, controllers, services and tests.
6. Produce a short implementation plan.
7. Identify dependencies and risks.
8. Implement in small steps.
9. Run tests.
10. Review security and authorization.
11. Update docs when needed.

Do not rebuild working code without a reason.

## Phase order
Phase 0:
- repository audit
- requirements audit
- ERD
- migration plan
- API contract
- GitHub setup
- environment setup
- Phase-0 decisions

Phase 1:
- Laravel/MySQL foundation
- auth
- RBAC
- 2FA
- audit
- CI

Phase 2:
- patients/MRN
- duplicate detection
- appointments
- DB booking constraint
- queue

Phase 3:
- EMR
- consultation
- clinical safety
- prescriptions
- reference data
- follow-up

Phase 4:
- lab
- pharmacy
- inventory

Phase 5:
- billing
- finance

Phase 6:
- React website
- dashboards
- patient portal
- i18n if approved

Phase 7:
- CRM
- notifications
- WhatsApp

Phase 8:
- analytics
- isolated non-clinical AI

Phase 9:
- security
- full tests
- UAT
- restore drill
- concurrency tests

Phase 10:
- production deployment
- monitoring

## Testing
For every critical feature, test:
- success
- validation failure
- unauthorized
- permission denied
- concurrency/race conditions where applicable
- audit behavior
- integration behavior

Critical integrations:
Appointment → Queue → Encounter
Prescription → Dispense → Stock → Invoice
Lab Order → Result → Verification

A UI warning is not a safety control. Prove backend/database enforcement.

## Stop conditions
Stop and request a decision if:
- a clinical rule is missing
- schema relationships conflict
- a migration could destroy data
- an authorization boundary is unclear
- a design request would weaken security
- a third-party provider is required but not approved
- PRD and DESIGN conflict on functionality

## Final expectation
Do not build a demo.
Build the system described in PRD.md, with DESIGN.md followed for UI once supplied.

Every feature must be implemented, authorized, validated, tested, documented, reviewed and CI-passing before it is considered complete.
