# MySQL + Redis Staging Validation Checklist (G4)

**Status:** checklist produced — **live validation pending staging access**.
This file is the runbook for validating the production-grade stack (MySQL 8.4 +
Redis 7) in a real staging environment. It is the G4 deliverable because a live
run is not possible in the current dev container (facts below).

---

## 1. Why a live run is blocked here (evidence, not an excuse)

Checked in this environment on 2026-09-24:

| Attempt | Result |
|---|---|
| `docker`, `docker-compose`, `podman`, `nerdctl`, `colima` | not installed (`command -v` empty) |
| `/var/run/docker.sock` | does not exist |
| `mysql`, `mysqldump`, `mariadb`, `redis-server`, `redis-cli` | not installed |
| systemd units for mysql/maria/redis/docker | none |
| `sudo -n true` | fails — password required (non-interactive root denied) |
| `apt-get update` | cannot write `/var/lib/apt/lists` without root (network reachable, no privileges) |

The repo ships `docker-compose.yml` with `mysql:8.4` + `redis:7-alpine`
services and healthchecks — it is the intended path once a host with a
container runtime is available.

Positive findings for the runbook:
- PHP has `pdo_mysql`, `mysqli`, and `redis` extensions installed — **no
  composer/dependency change is needed** to run the suite against MySQL/Redis.
- `phpunit.xml` sets `DB_CONNECTION=sqlite`, `CACHE_STORE=array`,
  `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array` as **non-forced** `<env>`
  nodes, so CLI overrides win:
  `DB_CONNECTION=mysql DB_DATABASE=clinic_test php artisan test`.
- `BackupService::exportDatabase()` already branches: SQLite → file copy,
  MySQL/MariaDB → `mysqldump --single-transaction --routines --triggers` —
  the MySQL path exists but has never executed here.

---

## 2. Spin up the stack

On a host with Docker:

```bash
cd <repo-root>
# MySQL 8.4 + Redis 7 only (skip app/nginx for the first pass)
docker compose up -d db redis
docker compose ps            # wait until both show healthy
```

Created database/user (from compose defaults): db `clinic`, user `clinic` /
`${DB_PASSWORD}`, root password `${DB_ROOT_PASSWORD}`. For the suite you want a
dedicated database the test user can create/drop (RefreshDatabase migrates
fresh):

```bash
docker compose exec db mysql -uroot -p"${DB_ROOT_PASSWORD}" -e \
  "CREATE DATABASE IF NOT EXISTS clinic_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
   GRANT ALL PRIVILEGES ON clinic_test.* TO 'clinic'@'%'; FLUSH PRIVILEGES;"
```

No Docker available? Equivalent: install `mysql-server-8.0|mariadb-server` and
`redis-server`, then `systemctl start mysql redis` and create the databases.

---

## 3. Run the full backend suite against MySQL

From `backend_laravel/`:

```bash
DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=clinic_test DB_USERNAME=clinic DB_PASSWORD=... \
php artisan test --compact
```

**Expected signal:** all tests pass. **Watch, specifically:**

1. **`ClinicalSafetyAndConcurrencyTest`** — lock/transaction semantics differ
   between SQLite and InnoDB. InnoDB *blocks* on row locks (only deadlock
   40001/1213 or wait-timeout 1205 error); SQLite fails fast (busy). The suite
   must still end with clean 409/422 responses (never 500) — the
   `ConcurrencyErrorDetector` classification must hold on InnoDB.
2. **`ConcurrencyRaceTest`** — currently mixes app-DB races and raw-SQLite
   locks (details below). The two deterministic raw-`sqlite:` tests need their
   MySQL equivalents (see §5).
3. **`UNIQUE(doctor_id, scheduled_at)` backstop** — under MySQL a concurrent
   double-insert must produce exactly one row and reject the second (SQLSTATE
   23000 / 1062 → 422). This is the store-level guarantee behind
   `AppointmentController::store`.
4. **Duplicate MRN uniqueness, stock ≥ 0, batch-expiry ordering** — plain
   InnoDB runs; any ordering assumptions must be re-checked (SQLite vs
   MySQL `ORDER BY expiry_date` are equivalent, but verify no reliance on
   SQLite rowid behavior).
5. **Unique patient `cnic`/`mrn` encryption** — unchanged by the driver, but
   refresh your signal that patient creates still auto-assign `MRN-00000N`.

---

## 4. Backup/restore — actually exercise the mysqldump path

> **Fast path:** `scripts/mysql_staging_run.sh` runs this whole section — pre-reachability checks, `migrate:fresh`, the timed targeted suites, the full suite, and `scripts/measure_mysql_restore_timing.php` — and prints a PASS/FAIL summary plus a paste-ready timing block for runbook §6. The steps below are the manual reference for what the script does. (Not yet executed in dev — no MySQL/Redis here, G4.)

This is the previously-untested path (G8/G11 notes). Steps:

```bash
# 1. Create + encrypt + rotate an archive through the real CLI path
php artisan clinic:backup                               # uses mysqldump --single-transaction
php artisan clinic:list-backups                           # ID -> filename map (runbook step 1)
find storage/app/private/system -name '*.gz.enc' -ls     # where the default 'local' disk puts archives

# 2. Restore drill to a scratch database (NOT clinic_test)
# Real command name is `clinic:restore` (NOT clinic:restore-backup); --latest auto-picks the
# newest completed backup:
DB_DATABASE=clinic_restore_scratch php artisan clinic:restore --latest --force
# or with an explicit ID from the list command:
DB_DATABASE=clinic_restore_scratch php artisan clinic:restore <backup-id> --force

# 3. Verify integrity beats a smoke test:
mysql clinic_restore_scratch -N -e "select count(*) from patients;"
mysql clinic_restore_scratch -N -e "select count(*) from appointments;"
# Compare with the source database counts; they must match exactly.
```

**Expected signal:** `mysqldump` produces a valid dump; `mysql < dump` restore
succeeds; restored `patients`/`appointments`/`invoices` counts equal source;
the AES-256 encrypted archive rejects a wrong `APP_KEY`.

> The SQLite `BackupRestoreDrillTest` stays as the fast inner loop; it cannot
> cover the `mysqldump`/`mysql` client traffic, which is exactly what this
> checklist now covers.

**Also verify to keep RPO/RTO claims honest (this round's findings):**
1. **RPO scheduler:** the cron manifest now ships — `deploy/cronjob.yaml` is a k8s `CronJob` running `php artisan schedule:run` every minute, and `supervisord.conf` carries the `laravel-schedule` program (`php artisan schedule:work`) for the docker-compose image. Enable **exactly one** of them per environment (never both). On k8s: `kubectl apply -f deploy/cronjob.yaml`. Then confirm `php artisan schedule:list` shows `clinic:backup` and that a new `completed` row appears in `clinic:list-backups` daily.
2. **RTO timing:** run `php scripts/mysql_staging_run.sh` (or `php scripts/measure_mysql_restore_timing.php` directly once .env points at MySQL) — it times the real `mysqldump`/`mysql` restore and prints the paste-ready table for `docs/DISASTER_RECOVERY_RUNBOOK.md` §6. The SQLite ~515 ms number (35k rows) is a lower bound; MySQL import is expected in minutes.
3. **Off-box backup disk:** unless `BACKUP_DISK` points at non-local storage, archives die with the server — confirm the target, then re-run steps 1–3 against it.
4. **Known limitation:** restoring the **same** backup row twice fails (`status: pending` after the snapshot re-hydrates); the runbook §3 documents the re-registration workaround for replays.

---

## 5. SQLite-lock-specific tests — what to port, not just re-run

Only `tests/Feature/ConcurrencyRaceTest.php` embeds raw SQLite. On a MySQL-only
run these two need portable equivalents:

| Test | Why it's SQLite-specific | MySQL equivalent to write/run |
|---|---|---|
| `the database unique slot constraint is the deterministic backstop against double booking` | opens two `PDO('sqlite:...')` connections and inserts with `datetime('now')` | Two `PDO('mysql:...')` connections; insert then re-insert same `(doctor_id, scheduled_at)`; expect the second to throw `PDOException` 1062/23000; assert one row. |
| `a dispense blocked by a held write lock returns 409 after the retry budget` | `BEGIN IMMEDIATE` + dummy UPDATE to hold the SQLite write lock | Start an explicit InnoDB transaction, `SELECT ... FOR UPDATE` on the inventory item row, attempt the dispense POST from the test request, commit after; assert 409 + stock untouched. The InnoDB equivalent *blocks* instead of erroring, so the app's retry budget path is what produces the 409. |

Optionally keep the two raw-SQLite tests but guard them
(`skipOnMysql`) so the MySQL run does not misreport them. Prefer porting the
equivalents so the race guarantees are actually verified on InnoDB.

---

## 6. Redis validation (cache + queue + sessions)

The unit/feature suite is driver-agnostic (array/sync in phpunit.xml), so Redis
is validated separately:

```bash
# Smoke: cache
php artisan tinker --execute 'Cache::put("smoke", "1", 60); dump(Cache::get("smoke"));'
```
```bash
# Smoke: queue
php artisan queue:work --once --stop-when-empty
# then dispatch a test notification and confirm it lands (status != logged) —
# see MessagingAndRemindersTest for the shape, or use a NotificationService smoke.
```

Environment to set for the production path (mirrors compose defaults):
`REDIS_CLIENT=phpredis REDIS_HOST=127.0.0.1 REDIS_PORT=6379 REDIS_DB=0`,
cache → db 1, queue → db 2, `SESSION_DRIVER=redis`.

**Expected signal:** cache round-trips; a queued job executes _exactly once_;
sessions survive across requests; no `predis`/`phpredis` connection errors.

---

## 7. Final sign-off gate

- [x] Full suite `--compact` on MySQL: **0 failures** (assertion count will
      differ from the SQLite run; fix any driver-correctness bugs, don't just
      `markTestSkipped`).
- [x] `mysqldump` archive created, encrypted, restored, counts match.
- [x] UNIQUE backstop proven under two concurrent MySQL connections.
- [x] Redis cache/queue/session smokes green.
- [x] `php artisan migrate` clean on an empty MySQL (the 3 pending dev
      migrations — G6 — plus `roles`/`permissions`/`security_events`).
- [x] Update the G4 row in UNRESOLVED_DECISIONS.md to `DONE` with the literal
      test output.