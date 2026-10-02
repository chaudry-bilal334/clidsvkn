<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Links a dispensing to the invoice raised for it. The link is one-to-one
     * and unique so the database — not application code — guarantees a
     * double-submitted or retried dispense can never raise a second invoice.
     * Nullable and additive: existing dispensings are unaffected.
     */
    public function up(): void
    {
        Schema::table('dispensings', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('quantity_dispensed')->constrained('invoices')->nullOnDelete();

            $table->unique('invoice_id', 'dispensings_invoice_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dispensings', function (Blueprint $table) {
            $table->dropUnique('dispensings_invoice_id_unique');
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
