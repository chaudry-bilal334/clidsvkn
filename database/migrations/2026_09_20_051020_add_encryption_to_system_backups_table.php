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
        Schema::table('system_backups', function (Blueprint $table) {
            $table->string('checksum_sha256', 64)->nullable()->after('file_path');
            $table->boolean('is_encrypted')->default(false)->after('checksum_sha256');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_backups', function (Blueprint $table) {
            $table->dropColumn(['checksum_sha256', 'is_encrypted']);
        });
    }
};
