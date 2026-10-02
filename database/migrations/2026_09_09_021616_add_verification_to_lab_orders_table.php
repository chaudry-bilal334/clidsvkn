<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the second-person verification workflow to lab orders. The verifier
     * must be a different user from the one who recorded the result; the
     * verification state drives `pending_verification`, `verified`, `amended`.
     */
    public function up(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->foreignId('recorded_by')->nullable()->after('result')->constrained('users')->nullOnDelete();
            $table->foreignId('verifier_id')->nullable()->after('recorded_by')->constrained('users')->nullOnDelete();
            $table->string('verification_state', 20)->nullable()->after('verifier_id');
            $table->dateTime('verified_at')->nullable()->after('verification_state');

            $table->index(['verification_state', 'verified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dropIndex(['verification_state', 'verified_at']);
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropConstrainedForeignId('verifier_id');
            $table->dropColumn(['verification_state', 'verified_at']);
        });
    }
};
