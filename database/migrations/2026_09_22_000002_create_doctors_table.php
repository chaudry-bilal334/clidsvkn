<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Doctor model representing a qualified clinician.
     * Linked to users table via one-to-one relationship.
     */
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->unique()->cascadeOnDelete();
            $table->string('license_number', 50)->unique();
            $table->string('specialization', 100)->nullable();
            $table->string('qualification', 150)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('bio', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('specialization');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
