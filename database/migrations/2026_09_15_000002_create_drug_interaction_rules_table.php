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
        Schema::create('drug_interaction_rules', function (Blueprint $table) {
            $table->id();
            $table->string('medicine_a', 120);
            $table->string('medicine_b', 120);
            $table->json('aliases_a')->nullable();
            $table->json('aliases_b')->nullable();
            $table->string('severity', 20)->default('moderate');
            $table->text('guidance')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['medicine_a', 'medicine_b']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drug_interaction_rules');
    }
};
