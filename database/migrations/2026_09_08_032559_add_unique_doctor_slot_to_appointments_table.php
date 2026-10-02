<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Replaces the plain composite read index with a unique constraint on
     * (doctor_id, scheduled_at). This is the database-level backstop against
     * double booking that the application-level slot check cannot guarantee
     * under concurrent requests.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['doctor_id', 'scheduled_at']);
            $table->unique(['doctor_id', 'scheduled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique(['doctor_id', 'scheduled_at']);
            $table->index(['doctor_id', 'scheduled_at']);
        });
    }
};
