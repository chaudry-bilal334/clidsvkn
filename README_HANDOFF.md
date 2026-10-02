# Clinic Management System — OpenCode Handoff

## Files

- `PRD.md` — product/engineering requirements
- `DESIGN.md` — clinic-specific UI/UX source of truth
- `OpenCode_Master_Prompt.md` — instructions for the coding agent
- `docs/Clinical_Management_System_DESIGN_Specification_v1.pdf` — design PDF reference

## Important

Do not ask OpenCode to blindly build everything in one step.

First run the OpenCode Master Prompt and require a repository/requirements audit, ERD plan, migration plan, API contract and unresolved-decision report.

After review/approval, implementation can start.

## Stack

Laravel + React + TypeScript + MySQL + Redis

## Team

Dev 1: Backend/MySQL/Security/DevOps  
Dev 2: Clinical/Doctor/Patient/EMR/Prescription Safety  
Dev 3: Pharmacy/Inventory/Lab Verification/Finance  
Dev 4: React/Portal/CRM/Communication/UX
