<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the sample-collection stage to lab orders (PRD §9 / DESIGN §11:
     * `Ordered → Sample Collected → Processing → Result Entered`). Purely
     * additive: existing orders keep their current status and remain valid.
     * `sample_collected_at` stays NULL for any order that predates this
     * migration or has not had its sample collected yet — no backfill, so
     * in-flight orders are never given fake collection data.
     *
     * `sample_identifier` is unique per branch *when set*: a composite unique
     * index on (branch_id, sample_identifier) allows any number of NULLs (an
     * order with no identifier) while rejecting a second non-NULL duplicate in
     * the same branch.
     */
    public function up(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dateTime('sample_collected_at')->nullable()->after('result');
            $table->foreignId('sample_collected_by')->nullable()->after('sample_collected_at')->constrained('users')->nullOnDelete();
            $table->string('sample_identifier', 100)->nullable()->after('sample_collected_by');

            $table->unique(['branch_id', 'sample_identifier'], 'lab_orders_branch_sample_identifier_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dropUnique('lab_orders_branch_sample_identifier_unique');
            $table->dropConstrainedForeignId('sample_collected_by');
            $table->dropColumn(['sample_collected_at', 'sample_identifier']);
        });
    }
};
