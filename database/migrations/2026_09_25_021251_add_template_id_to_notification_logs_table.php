<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Link each sent/logged notification back to the template that produced
     * it so audit trails can show which approved template generated a
     * message. The link is nullable and nullOnDelete: deleting a template
     * never destroys the log records that reference it.
     */
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->after('user_id');
            $table->foreign('template_id')->references('id')->on('notification_templates')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropColumn('template_id');
        });
    }
};
