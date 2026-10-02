<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Moves the dispense→invoice link from a per-line column to a per-dispense
     * -action group. One dispense call (one prescription, possibly several
     * medicines and FEFO batches) now raises exactly ONE invoice, owned by a
     * `dispense_groups` row whose `invoice_id` is unique — so the database,
     * not application code, guarantees a retried dispense can never raise a
     * second invoice.
     *
     * Existing per-line links are migrated: each dispensing that already
     * carries an `invoice_id` gets a group that owns that invoice, and the
     * per-line column is dropped (the group is the single source of truth).
     */
    public function up(): void
    {
        Schema::create('dispense_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('invoice_id', 'dispense_groups_invoice_id_unique');
        });

        Schema::table('dispensings', function (Blueprint $table) {
            $table->foreignId('dispense_group_id')->nullable()->after('dispensed_by')->constrained('dispense_groups')->nullOnDelete();
            $table->dateTime('voided_at')->nullable()->after('dispense_group_id');
        });

        // Backfill: turn each existing per-line invoice link into a group that
        // owns that invoice, then point the dispensing at the group.
        $dispensings = DB::table('dispensings')->whereNotNull('invoice_id')->get();

        foreach ($dispensings as $dispensing) {
            $groupId = DB::table('dispense_groups')->insertGetId([
                'prescription_id' => $dispensing->prescription_id,
                'invoice_id' => $dispensing->invoice_id,
                'created_by' => $dispensing->dispensed_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('dispensings')
                ->where('id', $dispensing->id)
                ->update(['dispense_group_id' => $groupId]);
        }

        Schema::table('dispensings', function (Blueprint $table) {
            $table->dropUnique('dispensings_invoice_id_unique');
            $table->dropConstrainedForeignId('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore the per-line invoice link from the group that owns it.
        $groups = DB::table('dispense_groups')->whereNotNull('invoice_id')->get();

        Schema::table('dispensings', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('quantity_dispensed')->constrained('invoices')->nullOnDelete();
        });

        foreach ($groups as $group) {
            DB::table('dispensings')
                ->where('dispense_group_id', $group->id)
                ->update(['invoice_id' => $group->invoice_id]);
        }

        Schema::table('dispensings', function (Blueprint $table) {
            $table->unique('invoice_id', 'dispensings_invoice_id_unique');
            $table->dropConstrainedForeignId('dispense_group_id');
            $table->dropColumn('voided_at');
        });

        Schema::dropIfExists('dispense_groups');
    }
};
