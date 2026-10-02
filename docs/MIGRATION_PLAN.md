# Migration Plan — Clinic Management System

**Version:** 1.0 (Phase-0 repository audit)
**Status:** 68 migration files; fully applied against the current dev database (see §3)

## 1. Rules (from PRD §11)

- Laravel migrations only — no manual production schema edits.
- FKs, indexes, unique constraints and transactions used throughout.
- Consistent timestamps/timezone.
- Synthetic data only in dev/staging.
- Every migration ships a safe `down()`; destructive drops are deliberate.

## 2. Execution order (dependency-respecting)

Migrations are ordered by filename timestamp as applied. The groups below are
the logical layers. **Do not reorder these manually.**

### Layer 0 — Framework
| File | Creates |
|---|---|
| `0001_01_01_000000_create_users_table` | `users` (+ password reset, sessions, cache, jobs) |
| `0001_01_01_000001_create_cache_table` | `cache`, `cache_locks` |
| `0001_01_01_000002_create_jobs_table` | `jobs`, `job_batches`, `failed_jobs` |
| `2026_09_06_073611_create_personal_access_tokens_table` | Sanctum `personal_access_tokens` |

### Layer 1 — Identity, access, base app columns
| File | Detail |
|---|---|
| `2026_09_06_000001_add_role_to_users_table` | `users.role` |
| `2026_09_06_073622_create_clinic_performance_tables` | dashboard/performance helpers |
| `2026_09_06_073623_add_notification_preferences_to_users_table` | user opt-ins |
| `2026_09_06_085701_add_patient_link_and_phone_to_users_table` | `users.patient_id`, `users.phone` |
| `2026_09_09_025201..` / `025226` | `users.branch_id`/`is_cross_branch`, `branches` |
| `2026_09_09_025207_add_two_factor_columns_to_users_table` | 2FA secret/recovery/confirmed-at |
| `2026_09_14_000004_create_roles_and_permissions_tables` *(pending)* | `roles`, `permissions`, 3 pivots |

### Layer 2 — Patients & documents
| File | Detail |
|---|---|
| `2026_09_06_081322_create_patients_table` | base patient record |
| `2026_09_08_032558_add_mrn_and_cnic_to_patients_table` | `mrn` (unique), `cnic` |
| `2026_09_09_021611_create_mrn_sequences_table` | MRN counter |
| `2026_09_09_025202_add_branch_id_to_patients_table` | branch scoping |
| `2026_09_14_141518_add_cnic_hash_to_patients_table` | protected CNIC hash |
| `2026_09_09_043002_create_medical_documents_table` | document storage |
| `2026_09_06_111211_create_data_export_requests_table` | privacy exports |

### Layer 3 — Scheduling (appointments, availability, queue)
| File | Detail |
|---|---|
| `2026_09_06_091734/091735` | `doctor_schedules`, `doctor_time_offs` |
| `2026_09_08_032559_add_unique_doctor_slot_to_appointments_table` | **DB double-booking backstop**: `UNIQUE(doctor_id, scheduled_at)` |
| `2026_09_09_021612_create_queue_entries_table` | queue/arrival |
| `2026_09_09_025203_add_branch_id_to_appointments_table` | branch scoping |

### Layer 4 — Clinical (EMR, safety reference, prescriptions)
| File | Detail |
|---|---|
| `2026_09_09_021613..021615` | `encounters`, `vitals`, `diagnoses` |
| `2026_09_06_081326/081327` | `prescriptions`, `prescription_items` |
| `2026_09_15_000001..000003` | `allergens`, `drug_interaction_rules`, `dosage_rules` (all `is_approved=false` until clinical sign-off) |
| `2026_09_15_000004/000005` | `patient_medical_histories`, `medication_reconciliations` |
| `2026_09_06_093533_create_clinical_decision_supports_table` | CDSS alerts |
| `2026_09_17_021438_add_finalization_to_clinical_records` *(pending)* | finalize/void/amend on `encounters` + `prescriptions` |
| `2026_09_14_000003_create_followups_table` | follow-up queue |

### Layer 5 — Laboratory
| File | Detail |
|---|---|
| `2026_09_06_081328_create_lab_orders_table` | `lab_orders` |
| `2026_09_09_021616_add_verification_to_lab_orders_table` | second-person verification |
| `2026_09_09_025206_add_branch_id_to_lab_orders_table` | branch scoping |

### Layer 6 — Pharmacy / inventory / procurement
| File | Detail |
|---|---|
| `2026_09_06_081329_create_inventory_items_table` | item master |
| `2026_09_06_081330_align_inventory_transactions_columns` | ledger column alignment (unit_price/total_amount/reason) |
| `2026_09_08_032600_create_inventory_batches_table` | batches + expiry |
| `2026_09_08_032601_add_inventory_batch_id_to_inventory_transactions_table` | per-batch ledger |
| `2026_09_09_021617_create_dispensings_table` | dispensing records |
| `2026_09_09_025204_add_branch_id_to_inventory_items_table` | branch scoping |
| `2026_09_14_152618..152620` | `suppliers`, `purchases`, `purchase_items` |

### Layer 7 — Finance
| File | Detail |
|---|---|
| `2026_09_06_081323/081324/081325` | `invoices`, `invoice_items`, `payments` |
| `2026_09_25_120000_create_jazzcash_transactions_table` | `jazzcash_transactions` gateway attempts (pending → completed/failed/cancelled) |
| `2026_09_09_021618_create_refunds_table` | linked refunds/reversals |
| `2026_09_09_025205_add_branch_id_to_invoices_table` | branch scoping |
| `2026_09_06_093531/093532` | `insurance_providers`, `claims` |
| `2026_09_14_152621/152622` | `expense_categories`, `expenses` |

### Layer 8 — CRM / AI / notifications
| File | Detail |
|---|---|
| `2026_09_14_000001/000002` | `leads`, `communications` |
| `2026_09_14_152623_create_ai_content_drafts_table` | non-clinical AI drafts |
| (notification_logs) | created within the clinic-performance migration |
| `2026_09_25_021250_create_notification_templates_table` | `notification_templates` (channel+name unique, `is_approved` default false, approver FKs, soft deletes) |
| `2026_09_25_021251_add_template_id_to_notification_logs_table` | `notification_logs.template_id` FK → `notification_templates` |

### Layer 9 — Audit / security / ops
| File | Detail |
|---|---|
| `2026_09_08_032557_create_audit_logs_table` | `audit_logs` |
| `2026_09_14_000005_create_security_events_table` *(pending)* | `security_events` (type/severity indexed, JSON payload) |
| `2026_09_06_111210_create_system_settings_table` | settings |
| `2026_09_06_120000_create_system_backups_table` | backups |

## 3. Current state summary

- **Migrations applied:** all 75 in the local `database/database.sqlite`
  (verified via `php artisan migrate:status`; `php artisan migrate` is a no-op).
- The three formerly-pending migrations
  (`2026_09_14_000004_create_roles_and_permissions_tables`,
  `2026_09_14_000005_create_security_events_table`,
  `2026_09_17_021438_add_finalization_to_clinical_records`) are applied, as are
  the notification-template migrations above.
- Tests run against a fresh DB each run (in-memory/refresh) and pass with the
  full migration set.

## 4. Apply to a new environment

```bash
php artisan migrate                # apply all layers in dependency order
php artisan db:seed --force        # deterministic demo data (idempotent, synthetic only)
```

For production-like MySQL:
1. Set `DB_CONNECTION=mysql`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
2. Review the two backstop/immutability points in the ERD before close:
   `UNIQUE(doctor_id, scheduled_at)` and ledger-`inventory_transactions`.
3. Reference-data approval flow (PRD §12/§22) must be completed **before**
   clinical testing; seeding never sets `is_approved=true` by itself.

## 5. Naming & review guidance for future migrations

- Keep timestamps ahead (`YYYY_MM_DD_HHMMSS`) so order stays chronological.
- One logical change per migration; add FKs with explicit
  `cascadeOnDelete`/`nullOnDelete`; add indexes for every foreign-key filter that
  powers a list/report query.
- Any **destructive** migration (drop column/table, change PK) goes through the
  decision/approval path (PRD §22) and requires a reviewed `down()`.