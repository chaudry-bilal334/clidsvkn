<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Aligns the existing inventory_transactions table with the columns the
     * pharmacy SPA already consumes (unit price, total amount, reason) without
     * discarding the original audit-focused notes column.
     */
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->decimal('unit_price', 12, 2)->default(0)->after('quantity');
            $table->decimal('total_amount', 12, 2)->default(0)->after('unit_price');
            $table->string('reason', 255)->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'total_amount', 'reason']);
        });
    }
};
