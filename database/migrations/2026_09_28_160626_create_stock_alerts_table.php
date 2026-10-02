<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Records stock expiry alerts so the scan is idempotent: a given batch at
     * a given threshold is alerted once, not on every scheduled run. The
     * composite unique index is the database-level guarantee.
     */
    public function up(): void
    {
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_batch_id')->constrained('inventory_batches')->cascadeOnDelete();
            $table->unsignedInteger('threshold_days')->default(0);
            $table->string('alert_type', 20);
            $table->string('message', 500)->nullable();
            $table->timestamps();

            $table->unique(['inventory_batch_id', 'threshold_days', 'alert_type'], 'stock_alerts_batch_threshold_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
    }
};
