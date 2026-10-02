<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add optimistic concurrency to medication reconciliations.
     *
     * DECISIONS_PENDING.md §8 recorded this as last-write-wins: two clinicians
     * editing the same row ended with the later write applied and the earlier
     * one silently discarded from the row (it survived only in `audit_logs`).
     * That was documented as *unspecified* behaviour, not a designed contract,
     * and it was left open pending a decision.
     *
     * The conservative resolution is optimistic locking: the client sends the
     * `version` it read, the update is refused with 409 if the row moved on.
     * Nothing is overwritten silently, and the loser is told to reload rather
     * than discovering the loss later.
     *
     * The column is a plain unsigned integer defaulting to 1, not a
     * timestamp: `updated_at` has one-second granularity on some MySQL
     * configurations, so two edits inside the same second would compare equal
     * and the check would pass when it should have failed.
     *
     * Existing rows all start at version 1, which is correct: nothing has
     * conflicted yet.
     */
    public function up(): void
    {
        Schema::table('medication_reconciliations', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_reconciliations', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
