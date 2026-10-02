<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_content_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 255);
            $table->string('content_type', 40);
            $table->longText('content');
            $table->enum('status', ['draft', 'pending_review', 'approved', 'rejected', 'published'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('content_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_content_drafts');
    }
};
