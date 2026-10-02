<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Reusable WhatsApp/SMS/email templates with a human approval workflow
     * (PRD §4 module 12, DESIGN §15). Templates are created as drafts
     * (is_approved = false) and only become sendable after an authenticated
     * actor with the notifications.manage permission approves them. The
     * seeder never sets is_approved = true on its own, mirroring the
     * clinical reference-data discipline.
     */
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 40);
            $table->string('name', 120);
            $table->string('subject', 255)->nullable();
            $table->text('body');
            $table->boolean('is_approved')->default(false);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['channel', 'name']);
            $table->index(['channel', 'is_approved', 'created_at']);

            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
