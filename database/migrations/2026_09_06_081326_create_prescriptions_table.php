<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->string('prescription_number', 40)->unique();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('patient_name', 150)->nullable();
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->string('doctor_name', 150)->nullable();
            $table->string('doctor_license_no', 50)->nullable();
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->timestamps();

            $table->index('patient_id');
            $table->index('appointment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
