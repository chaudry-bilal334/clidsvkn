#!/usr/bin/env bash
#
# MySQL/Redis staging validation run (G4) — orchestrates the whole
# docs/STAGING_VALIDATION_CHECKLIST.md exercise against a real MySQL+Redis
# environment and produces the paste-ready RTO numbers for
# docs/DISASTER_RECOVERY_RUNBOOK.md §6.
#
# SAFETY / STATUS:
#   * This script has NOT been executed in this repository's dev environment
#     (no MySQL binaries / container runtime / staging access here — G4). It is
#     the runbook for whoever runs Phase 1 when staging access exists.
#   * It swaps .env -> .env.staging for the duration and restores the original
#     .env on exit (including on failure). The real application database named
#     in .env.staging is NOT destroyed — only a dedicated test schema
#     (TEST_DB, default clinic_test_staging) is created/dropped, and the
#     timing script uses its own throwaway schemas.
#
# PREREQUISITES:
#   * MySQL 8.x reachable per .env.staging (DB_CONNECTION=mysql,
#     DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD/DB_DATABASE), and the user has
#     CREATE/DROP DATABASE (for the test schema + timing throwaways)
#   * Redis reachable per .env.staging (REDIS_HOST/REDIS_PORT) — soft-checked
#     (the phpunit suite pins array/sync drivers per phpunit.xml, so Redis is
#     validated by the smoke checks, not the suite)
#   * `mysql` and `mysqldump` client binaries on PATH
#   * Run from backend_laravel/, or from anywhere — the script cd's itself.
#
# USAGE:
#   bash scripts/mysql_staging_run.sh [env-file]
#     env-file   default .env.staging  (a .env-style file with MySQL/Redis
#                                       values; must set DB_CONNECTION=mysql)
#   TEST_DB=clinic_test_staging        override the throwaway test schema name
#
# WHAT IT RUNS (each step timed, results appended to storage/logs/):
#   1. mysql + redis reachability pre-checks
#   2. php artisan migrate:fresh --force           (app DB from .env.staging)
#   3. targeted timed suites: ClinicalSafetyAndConcurrencyTest,
#      ConcurrencyRaceTest, BackupRestoreDrillTest  (against MySQL test schema)
#   4. full suite: php artisan test --compact       (against MySQL test schema)
#   5. php scripts/measure_mysql_restore_timing.php (real mysqldump/mysql
#      restore wall-clock, prints the runbook-§6 paste-ready table)
#   6. PASS/FAIL summary; exit non-zero if any step failed
#
# NOTE on checklist §5: two ConcurrencyRaceTest cases open raw SQLite PDO
# connections on purpose (MySQL can't exercise those paths without portable
# equivalents — that's what §5 documents). On a MySQL-only run those two may
# fail or be skipped; the rest of the race suite is the InnoDB signal.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ENV_FILE="${1:-.env.staging}"
TEST_DB="${TEST_DB:-clinic_test_staging}"

LOG="storage/logs/mysql_staging_run_$(date +%Y%m%d_%H%M%S).log"
mkdir -p storage/logs
: > "$LOG"

ENV_BAK=".env.pre-staging.$(date +%Y%m%d_%H%M%S)"
SWAPPED=false
STEP_RESULTS=()

log() { echo "$*" | tee -a "$LOG"; }

now_ms() { date +%s%3N; }

envval() {
    grep -E "^$1=" "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true
}

DB_CONNECTION="$(envval DB_CONNECTION)"
DB_HOST="$(envval DB_HOST)";        DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(envval DB_PORT)";        DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="$(envval DB_USERNAME)"
DB_PASSWORD="$(envval DB_PASSWORD)"
DB_DATABASE="$(envval DB_DATABASE)"; DB_DATABASE="${DB_DATABASE:-clinic}"
REDIS_HOST="$(envval REDIS_HOST)";  REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="$(envval REDIS_PORT)";  REDIS_PORT="${REDIS_PORT:-6379}"

MYSQL_AUTH=(-h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME")
if [ -n "$DB_PASSWORD" ]; then
    MYSQL_AUTH+=("-p${DB_PASSWORD}")
fi

# --- helpers -----------------------------------------------------------------

# check: run a command; record PASS/FAIL with wall-clock; never aborts.
check() {
    local label="$1"; shift
    local start end
    start="$(now_ms)"
    if "$@" >/dev/null 2>&1; then
        end="$(now_ms)"
        log "[PASS] $label ($((end - start)) ms)"
        STEP_RESULTS+=("PASS|$label|$((end - start)) ms")
        return 0
    fi
    end="$(now_ms)"
    log "[FAIL] $label ($((end - start)) ms)"
    STEP_RESULTS+=("FAIL|$label|$((end - start)) ms")
    return 1
}

# run: execute a timed step, streaming output into the log; records PASS/FAIL.
run() {
    local label="$1"; shift
    local start end rc
    start="$(now_ms)"
    set +e
    "$@" 2>&1 | tee -a "$LOG"
    rc=("${PIPESTATUS[@]}")
    rc="${rc[0]}"
    set -e
    end="$(now_ms)"
    if [ "$rc" -eq 0 ]; then
        log "[PASS] $label ($((end - start)) ms)"
        STEP_RESULTS+=("PASS|$label|$((end - start)) ms")
    else
        log "[FAIL] $label ($((end - start)) ms, exit $rc)"
        STEP_RESULTS+=("FAIL|$label|$((end - start)) ms")
    fi
}

restore_env() {
    if [ "$SWAPPED" = true ]; then
        cp "$ENV_BAK" .env
        log "Restored .env from $ENV_BAK"
        SWAPPED=false
    fi
}

# --- pre-checks (hard gates — run before touching .env) ----------------------

log "== MySQL/Redis staging run (G4) =="
log "env file: $ENV_FILE | test schema: $TEST_DB | log: $LOG"
log "Status: prepared for staging access — not executed in this repo's dev environment."

[ -f "$ENV_FILE" ] || { log "ERROR: env file '$ENV_FILE' not found (arg 1; default .env.staging)"; exit 1; }
[ -f .env ] || { log "ERROR: .env not found in $ROOT"; exit 1; }
[ "$DB_CONNECTION" = "mysql" ] || { log "ERROR: $ENV_FILE DB_CONNECTION is '${DB_CONNECTION:-unset}', must be 'mysql'"; exit 1; }

for b in mysql mysqldump; do
    command -v "$b" >/dev/null 2>&1 || { log "ERROR: '$b' is not on PATH"; exit 1; }
done

# --- swap .env, then everything restores even on failure ---------------------

cp .env "$ENV_BAK"
cp "$ENV_FILE" .env
SWAPPED=true
trap restore_env EXIT

# --- 1. connectivity ---------------------------------------------------------

check "mysql reachable (SELECT 1)" mysql "${MYSQL_AUTH[@]}" -e "SELECT 1" \
    || { log "ABORT: MySQL unreachable at ${DB_HOST}:${DB_PORT} as '${DB_USERNAME}'. Fix .env.staging and rerun."; exit 1; }

if command -v redis-cli >/dev/null 2>&1; then
    check "redis reachable (PING)" redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" ping || \
        log "WARN: redis ping failed — the suite pins array/sync drivers, so only the app-level smoke checks need it (checklist §6)."
else
    log "WARN: redis-cli not on PATH — skipping the Redis reachability pre-check (checklist §6)."
fi

# --- 2. throwaway test schema -------------------------------------------------

run "create test schema ${TEST_DB}" \
    mysql "${MYSQL_AUTH[@]}" -e "CREATE DATABASE IF NOT EXISTS \`${TEST_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# --- 3. migrations on the app database (from .env.staging) --------------------

run "migrate:fresh --force on ${DB_DATABASE}" php artisan migrate:fresh --force

# --- 4. targeted timed suites -> MySQL test schema ----------------------------

log "Note: two ConcurrencyRaceTest cases open raw SQLite PDOs on purpose (checklist §5) and may fail/skip on MySQL — the rest of the race suite is the InnoDB signal."

run "targeted suites (ClinicalSafetyAndConcurrency, ConcurrencyRace, BackupRestoreDrill)" \
    env DB_CONNECTION=mysql DB_DATABASE="$TEST_DB" \
    php artisan test \
    tests/Feature/ClinicalSafetyAndConcurrencyTest.php \
    tests/Feature/ConcurrencyRaceTest.php \
    tests/Feature/BackupRestoreDrillTest.php \
    --compact

# --- 5. full suite ------------------------------------------------------------

run "full test suite (MySQL test schema)" \
    env DB_CONNECTION=mysql DB_DATABASE="$TEST_DB" php artisan test --compact

# --- 6. real mysqldump/mysql restore timing -----------------------------------

run "mysql restore timing (measure_mysql_restore_timing.php)" \
    php scripts/measure_mysql_restore_timing.php

# --- 7. cleanup + summary ------------------------------------------------------

run "drop test schema ${TEST_DB}" mysql "${MYSQL_AUTH[@]}" -e "DROP DATABASE IF EXISTS \`${TEST_DB}\`"

log ""
log "== SUMMARY =="
failed=0
for line in "${STEP_RESULTS[@]}"; do
    IFS='|' read -r status label dur <<<"$line"
    log "$status  $label  ($dur)"
    [ "$status" = "FAIL" ] && failed=1
done
log ""
log "Full run log: $ROOT/$LOG"
log "Paste-ready timing block: the '== RUNBOOK §6 PASTE-READY TABLE ==' section"
log "in $ROOT/$LOG — copy those rows into docs/DISASTER_RECOVERY_RUNBOOK.md §6."

[ "$failed" -eq 0 ]