<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_events')) {
            Schema::table('security_events', function (Blueprint $table): void {
                $table->text('user_agent')->nullable();
                $table->json('payload')->nullable();
                $table->dropColumn('details');
                $table->index('type');
            });

            return;
        }

        Schema::create('security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 80);
            $table->string('severity', 20);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('severity');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('security_events')) {
            return;
        }

        Schema::table('security_events', function (Blueprint $table): void {
            $table->dropIndex(['type']);
            $table->dropColumn(['user_agent', 'payload']);
            $table->text('details')->nullable();
        });
    }
};
