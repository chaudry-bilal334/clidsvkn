<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the lookup tables the clinic SPA relies on and declares the
     * composite indexes optimised for the most common read patterns:
     * scheduling lookups, patient-facing status filters, inventory history,
     * security-rate reporting and unread notification digests.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('doctor_id');
            $table->dateTime('scheduled_at');
            $table->string('status', 30)->default('scheduled');
            $table->string('reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'scheduled_at']);
            $table->index(['patient_id', 'status']);
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_item_id');
            $table->integer('quantity')->default(0);
            $table->string('type', 30);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['inventory_item_id', 'created_at']);
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('severity', 20);
            $table->string('type', 60);
            $table->text('details')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['severity', 'created_at']);
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('channel', 40);
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->string('status', 30)->default('pending');
            $table->dateTime('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('appointments');
    }
};
