<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Finalized clinical records are locked and must never be silently edited.
     * Corrections are made by void/amend while preserving the original record,
     * as required by the PRD / Master Prompt clinical-safety baseline.
     */
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->string('status', 20)->default('draft')->after('appointment_id');
            $table->timestamp('finalized_at')->nullable()->after('notes');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('finalized_by');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable()->after('voided_by');
            $table->foreignId('amends_encounter_id')->nullable()->after('void_reason')
                ->constrained('encounters')->nullOnDelete();

            $table->index(['patient_id', 'status']);
        });

        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->timestamp('finalized_at')->nullable()->after('issued_at');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('finalized_by');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable()->after('voided_by');
            $table->foreignId('amends_prescription_id')->nullable()->after('void_reason')
                ->constrained('prescriptions')->nullOnDelete();

            $table->index(['patient_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropIndex(['patient_id', 'status']);
            $table->dropConstrainedForeignId('amends_encounter_id');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['status', 'finalized_at', 'voided_at', 'void_reason']);
        });

        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropIndex(['patient_id', 'status']);
            $table->dropConstrainedForeignId('amends_prescription_id');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['finalized_at', 'voided_at', 'void_reason']);
        });
    }
};
