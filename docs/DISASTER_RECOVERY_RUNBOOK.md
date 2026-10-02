# Disaster Recovery Runbook — Clinic Management System

**Target (owner-approved):** RPO ≤ 24h (≤ 24h of data loss on disaster) · RTO ≤ 1h (restore service within 1 hour of declaring disaster).

**Read time: < 1 minute. Execution time: mechanical restore is seconds (SQLite) — see §6 for the budget.**

> Copy-paste the commands in **bold boxes**. Stop, breathe, verify each step's "Expected signal" before moving on.

---

## 0. Before you type anything

- You are the **on-call**. You have access to: **(a)** the production server/terminal, **(b)** the `.env` value of `APP_KEY` (and it must be the **same key that encrypted the backups**), **(c)** the deploy pipeline if the server itself is gone.
- If the **database is completely gone (server dead)**: provisioning a fresh server is *outside* the restore mechanics below. Budget it: OS + PHP + `composer install` + `php artisan migrate` + deploy can consume most of the 1h RTO **if** it is not automated. Have that pipeline rehearsed — this runbook covers the restore part.
- If the database is intact but **corrupted/lost logically** (data loss, botched job, rolled-back migration): skip provisioning, go to §1.

---

## 1. Confirm you have a backup to restore

```bash
cd /path/to/backend_laravel
php artisan clinic:list-backups
```
**Expected signal:** a table of backups — ID, Filename, Size, Status (`completed`), Created. Pick the newest `completed` row and note its **ID**.

- No rows shown? → jump to **§5 (metadata lost)** — the archives may still be on disk.
- Rows shown but all `pending`/`failed`? → something is wrong with generation; the newest `completed` archive is your best point-in-time; if none exists, you are at **full data loss** — escalate immediately (data-recovery of the last export falls back to any off-box copy; see §5).

---

## 2. Restore the latest completed backup

**Fastest (recommended): auto-find the newest completed backup**
```bash
cd /path/to/backend_laravel
php artisan clinic:restore --latest --force
```
**Or by explicit ID** (from §1):
```bash
php artisan clinic:restore <BACKUP_ID> --force
```
**Expected signal:** the command prints the **Backup Details** table (verify *Size* is non-zero and *Checksum* is present), then `Starting restore…` → `Database restored successfully!` and exits **0**. If it exits non-zero, read the red `Restore failed:` line — the three common causes are §3.

> During the restore the live DB file is replaced in place. Do **not** run this while traffic is live without a maintenance flag — plan a short maintenance window (the restore itself is ~0.5 s for SQLite, see §6; MySQL takes longer).

---

## 3. Common failures (and the honest answer)

| Symptom | Cause | Fix |
|---|---|---|
| `Backup #N is not completed (status: pending)` | The `system_backups` row is stale — the archive's snapshot row was captured mid-generation (also happens if you restore the **same** backup a **second** time; the just-restored snapshot marks it `pending`). | **Don't restore the same row twice.** If you must re-restore, re-create a row pointing at the archive: `php artisan tinker --execute 'App\Models\SystemBackup::create(["filename"=>"manual.sql.gz.enc","file_path"=>"system/db_dump_<name>.gz.enc","status"=>App\Models\SystemBackup::STATUS_COMPLETED,"is_encrypted"=>true]);'` (fill the real filename from `clinic:list-backups` output). |
| `Restore failed: Backup file not found in storage` | Archive missing from the backup disk (e.g. rotated by the 30-day retention, or disk was re-provisioned). | Check the backup disk (see §5). Your most recent archive may be gone → restore the newest one still on disk. |
| `Restore failed: Checksum verification failed` | Archive corrupted in transit/storage, or `APP_KEY` differs from the backup era (**key mismatch shows up here too** — wrong key decrypts to garbage that fails the checksum). | First verify `APP_KEY` in `.env` == the key that created the archive. No fix for genuine corruption — find the previous good archive. |

---

## 4. Verify the restore (never skip this)

```bash
cd /path/to/backend_laravel
php artisan tinker --execute 'dump(["patients"=>App\Models\Patient::count(),"appointments"=>DB::table("appointments")->count()]);'
```
**Expected signal:** counts ≥ your last known-good values (compare with the backup's creation time). Also smoke-test with a real query through the API layer:
*(deepest honest check: the restore's own drill — `BackupRestoreDrillTest` — proves row-for-row integrity on SQLite; the source-of-truth signal is the counts + one real record lookup.)*

Then restart queues/workers so nothing in memory reseeds data from the pre-restore state:
```bash
php artisan queue:restart
```
Finally, **start a fresh `clinic:backup` immediately** so the new post-restore state is captured for the next disaster:
```bash
php artisan clinic:backup
```
**Expected signal:** a new `completed` row in `php artisan clinic:list-backups`.

---

## 5. Metadata lost (fresh server / wiped DB) — manual path

The CLI finds backups **by the `system_backups` table**, which lives inside the DB. If the DB (or the whole server) is gone, that table is gone with it — but the **archive files** may still be on the backup disk.

```bash
cd /path/to/backend_laravel
# 1. (fresh server only) rebuild schema first
php artisan migrate --force
# 2. make sure the APP_KEY is the same .env key that encrypted the archives — non-negotiable
grep ^APP_KEY= .env
# 3. see what archives actually exist on the backup disk
php artisan tinker --execute 'dump(collect(Illuminate\Support\Facades\Storage::disk(config("filesystems.backup_disk", "local"))->allFiles("system"))->map(fn($f)=>[$f, Illuminate\Support\Facades\Storage::disk(config("filesystems.backup_disk","local"))->lastModified($f)]));'
# 4. re-register the newest archive as a completed backup (checksum skipped on purpose: it lived in the lost DB)
php artisan tinker --execute '
$disk = Illuminate\Support\Facades\Storage::disk(config("filesystems.backup_disk", "local"));
$file = collect($disk->allFiles("system"))->sortBy(fn($f) => $disk->lastModified($f))->last();
throw_if(!$file, RuntimeException::class, "no archive on backup disk");
App\Models\SystemBackup::create(["filename" => basename($file), "file_path" => $file, "status" => App\Models\SystemBackup::STATUS_COMPLETED, "is_encrypted" => true, "checksum_sha256" => null]);
echo "Registered: ".$file.PHP_EOL;
'
# 5. restore it
php artisan clinic:restore --latest --force
```
**Expected signal:** a row is registered, and §4's verification passes. **Honest caveat:** archive integrity was verified purely by the encrypted-bytes decrypting + gzip decompressing correctly — the SHA-256 was only stored in the DB you just lost. If you have the original run's checksum logged anywhere (audit logs), set it back with `App\Models\SystemBackup::where("filename", basename($file))->update(["checksum_sha256" => "<hex>"])` and re-verify manually.

If `allFiles("system")` is empty: backups were on the **local disk of the dead server** — gone with it. This is exactly why §7 ships archives off-box.

---

## 6. Measured restore evidence (this repo, SQLite dev profile)

`scripts/measure_restore_timing.php` (re-runnable: `php scripts/measure_restore_timing.php`) times the real pipeline on a throwaway SQLite DB seeded to a realistic size and restores through the real `clinic:restore --force`. **MySQL staging numbers go here too:** `scripts/measure_mysql_restore_timing.php` (or the whole `scripts/mysql_staging_run.sh` Phase-1 run) seeds the same dataset into throwaway MySQL schemas, runs the real `mysqldump`/`mysql` client traffic, and prints this table ready to paste (G4 — not yet run in dev):

| Phase | Wall-clock |
|---|---|
| Dataset | 35,000 rows (5,000 patients + 30,000 appointments), 10.9 MB SQLite |
| Encrypted archive on disk | 5.2 MB |
| Locate + download (backup disk → temp) | **11.2 ms** |
| Decrypt (AES-256-CBC, APP_KEY) | **221.0 ms** |
| Checksum verify (SHA-256) | **39.3 ms** |
| Decompress (gzip -9) | **176.8 ms** |
| Import (SQLite file-copy) | **10.4 ms** |
| **End-to-end `clinic:restore --force`** | **~515 ms (exit 0, integrity pass)** |
| `clinic:backup` generation (for context) | ~2.2 s |

- **RTO budget:** 1h target minus mechanical restore (~0.5 s SQLite) leaves the whole hour for **detection → access → decision → verify → communicate**. On MySQL (staging, G4) the import step dominates (mysqldump replay + index rebuild) — expect **minutes** at this scale; run `scripts/mysql_staging_run.sh` and paste its `== RUNBOOK §6 PASTE-READY TABLE ==` output into this table.
- **RPO evidence:** `routes/console.php` schedules `clinic:backup` **daily** via `->daily()->withoutOverlapping()`; retention is **30 days** (`rotateOldBackups`). RPO=24h only holds if the Laravel **scheduler is actually invoked**. The mechanism ships in the repo — enable exactly one per environment:
  - **k8s:** `deploy/cronjob.yaml` (a `CronJob` running `php artisan schedule:run` every minute) — apply with `kubectl apply -f deploy/cronjob.yaml`.
  - **docker-compose:** the `laravel-schedule` supervisord program in `supervisord.conf` runs `php artisan schedule:work` (the container-native cron loop).
  - Never run both in one environment — scheduled tasks would double-fire.
  Verify with `php artisan schedule:list` and by confirming a new `completed` row appears in `clinic:list-backups` each day (this daily check is also the only detector when the scheduler itself is down — see §7 risk 5).

---

## 7. Standing risks (know them before the disaster is yours)

1. **Backups on the app server's local disk** (`filesystems.backup_disk` → default `local` = `storage/app/private/system/` here). A dead server = dead backups. Set **`BACKUP_DISK`** in `.env` (config: `config/filesystems.php` → `backup_disk`) to an **off-box disk** (S3 etc.) and abort the migration until that is wired at staging/deploy.
2. **Second restore on the same row** will fail with `status: pending` (the restored snapshot re-hydrates the archive row as pending). Restore once per archive row; use §3's re-registration if you need to replay.
3. **`APP_KEY` is the encryption key for the archives.** Rotating it silently orphans every backup. Keep a cold copy of the key out-of-band.
4. **Backup failures now auto-alert via SMS (this round).** `clinic:backup` exits non-zero on any failure (generation error or the no-admin abort) and SMS-pages the ops contact through the existing Twilio driver. **Recipient:** `CLINIC_BACKUP_ALERT_SMS_TO` in `.env`, falling back to the first Admin user's phone. Requires `TWILIO_SMS_ENABLED=true` + `TWILIO_SID`/`TWILIO_AUTH_TOKEN`/`TWILIO_FROM` — the same channel patient reminders use. If the provider is unconfigured, the recipient opted out, or the delivery returns an error, the attempt degrades to a `notification_logs` row (`logged`/`skipped`/`failed`) + the audit log, and the command **still exits non-zero** — a broken alert can never mask the backup failure. **On receiving an alert:** (1) `php artisan clinic:list-backups` — confirm the newest `completed` archive still exists; (2) `tail -n 100 storage/logs/laravel.log` — find the `system_backup` context (backup ID, error); (3) fix the root cause (disk full, DB file missing, permissions); (4) re-run `php artisan clinic:backup` and confirm a new `completed` row appears.
5. **A backup that never runs cannot send a failure alert.** Alerting fires only when the scheduled run executes *and* fails. If the scheduler (the `deploy/cronjob.yaml` CronJob or the `laravel-schedule` supervisord program — §6) is missing or broken, no run happens, no SMS fires, and the only detector is the routine daily check — confirm a **new `completed` row** appears in `php artisan clinic:list-backups` each morning. Keep the scheduler installed and on the daily checklist.