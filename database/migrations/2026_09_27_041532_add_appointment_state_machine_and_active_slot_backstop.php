<?php

use App\Models\Appointment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Appointment state machine + the active-slot double-booking backstop
 * (PRD §6, §11).
 *
 * Two problems are fixed here:
 *
 * 1. The old `UNIQUE(doctor_id, scheduled_at)` backstop was unconditional, so a
 *    cancelled or no-show appointment permanently burned its slot and the
 *    patient could never be re-booked. MySQL ignores NULLs in a unique index,
 *    so a generated column that yields NULL for a released slot makes the
 *    constraint apply to *active* slots only — exactly what §11 asks for.
 *
 * 2. Status vocabulary moves to the PRD §6 lifecycle. Legacy rows are mapped
 *    forward rather than left on values the state machine would reject.
 */
return new class extends Migration
{
    /**
     * Legacy status values mapped onto the PRD §6 lifecycle.
     *
     * @var array<string, string>
     */
    private const LEGACY_STATUS_MAP = [
        'requested' => Appointment::STATUS_BOOKED,
        'scheduled' => Appointment::STATUS_BOOKED,
        'confirmed' => Appointment::STATUS_CONFIRMED,
        'arrived' => Appointment::STATUS_ARRIVED,
        'checked_in' => Appointment::STATUS_ARRIVED,
        'waiting' => Appointment::STATUS_WAITING,
        'in_consultation' => Appointment::STATUS_IN_CONSULTATION,
        'completed' => Appointment::STATUS_COMPLETED,
        'cancelled' => Appointment::STATUS_CANCELLED,
        'no_show' => Appointment::STATUS_NO_SHOW,
    ];

    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('status_changed_at')->nullable()->after('status');
            $table->dateTime('cancelled_at')->nullable()->after('status_changed_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');

            // The legacy default was `scheduled`, which is not in the PRD §6
            // vocabulary, so any insert omitting `status` would land outside
            // the state machine. `booked` is the entry rung.
            $table->string('status', 30)->default(Appointment::STATUS_BOOKED)->change();

            $table->index(['status', 'scheduled_at']);
        });

        $this->migrateLegacyStatuses();

        // MySQL: a STORED generated column + a unique index over it. The
        // column is NULL whenever the appointment no longer holds the slot, so
        // several released rows may share the same doctor/time.
        if (DB::connection()->getDriverName() === 'mysql') {
            $active = implode(',', array_map(
                fn (string $status): string => "'".$status."'",
                Appointment::ACTIVE_STATUSES,
            ));

            DB::statement(
                'ALTER TABLE `appointments`
                 ADD COLUMN `active_slot` DATETIME
                 GENERATED ALWAYS AS (
                     CASE WHEN `status` IN ('.$active.')
                          THEN `scheduled_at` ELSE NULL END
                 ) STORED'
            );

            DB::statement('ALTER TABLE `appointments` DROP INDEX `appointments_doctor_id_scheduled_at_unique`');
            DB::statement('ALTER TABLE `appointments` ADD UNIQUE INDEX `appointments_active_slot_unique` (`doctor_id`, `active_slot`)');
        } else {
            // SQLite has no generated columns before 3.31 and MySQL 8 is the
            // supported production store (PRD §11). Portable backstop: a
            // partial unique index covering only the held-slot statuses.
            $held = implode(',', array_map(
                fn (string $status): string => "'".$status."'",
                Appointment::ACTIVE_STATUSES,
            ));

            Schema::table('appointments', function (Blueprint $table) {
                $table->dropUnique(['doctor_id', 'scheduled_at']);
            });

            DB::statement(
                'CREATE UNIQUE INDEX appointments_active_slot_unique
                 ON appointments (doctor_id, scheduled_at)
                 WHERE status IN ('.$held.')'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `appointments` DROP INDEX `appointments_active_slot_unique`');
            DB::statement('ALTER TABLE `appointments` DROP COLUMN `active_slot`');
        } else {
            // SQLite has no `DROP INDEX IF EXISTS`; check the catalogue first.
            $exists = collect(DB::select("PRAGMA index_list('appointments')"))
                ->contains(fn (object $index): bool => $index->name === 'appointments_active_slot_unique');

            if ($exists) {
                DB::statement('DROP INDEX appointments_active_slot_unique');
            }
        }

        if (! $this->hasIndex('appointments', 'appointments_doctor_id_scheduled_at_unique')) {
            DB::statement(
                'CREATE UNIQUE INDEX appointments_doctor_id_scheduled_at_unique ON appointments (doctor_id, scheduled_at)'
            );
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('status', 30)->default('scheduled')->change();
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn(['status_changed_at', 'cancelled_at', 'cancellation_reason']);
        });
    }

    /**
     * Whether a named index exists on the table (SQLite / MySQL).
     */
    private function hasIndex(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $tableName = DB::getTablePrefix().$table;

            return DB::selectOne(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                [$tableName, $index],
            ) !== null;
        }

        return collect(DB::select("PRAGMA index_list('{$table}')"))
            ->contains(fn (object $candidate): bool => $candidate->name === $index);
    }

    /**
     * Fold legacy status strings onto the PRD §6 vocabulary.
     */
    private function migrateLegacyStatuses(): void
    {
        $known = array_keys(self::LEGACY_STATUS_MAP);

        foreach (self::LEGACY_STATUS_MAP as $legacy => $modern) {
            if ($legacy === $modern) {
                continue;
            }

            DB::table('appointments')
                ->where('status', $legacy)
                ->update(['status' => $modern]);
        }

        // Anything outside the vocabulary lands on `booked` rather than
        // becoming a state the machine can never move out of.
        DB::table('appointments')
            ->whereNotIn('status', $known)
            ->update(['status' => Appointment::STATUS_BOOKED]);
    }
};
