<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Consents model capturing patient consent for treatment, data processing,
     * communications, and specific procedures. All clinical operations require
     * valid consent on file.
     */
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // obtained by
            $table->enum('consent_type', [
                'treatment',
                'data_processing',
                'communications',
                'billing',
                'lab_procedures',
                'pharmacy_dispensing',
                'telehealth',
            ]);
            $table->text('scope')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('signed_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('withdrawn_by')->nullable();
            $table->date('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'consent_type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
