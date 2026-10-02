<?php

use App\Models\Patient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds searchable normalized identity columns to patients.
     *
     * patients.cnic is stored with Laravel's non-deterministic `encrypted`
     * cast (random IV per write), so it cannot be matched over SQL. A
     * deterministic HMAC blind index restores indexed exact-CNIC lookups
     * for duplicate detection while keeping the raw value encrypted.
     *
     * phone_normalized and email_normalized are formatting-normalized copies
     * so exact phone/email duplicate checks can run as indexed SQL queries
     * instead of scanning and normalizing every row in PHP.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->char('cnic_hash', 64)->nullable()->after('cnic');
            $table->index('cnic_hash');

            $table->string('phone_normalized', 40)->nullable()->after('phone');
            $table->index('phone_normalized');

            $table->string('email_normalized', 150)->nullable()->after('email');
            $table->index('email_normalized');
        });

        Patient::query()
            ->whereNotNull('cnic')
            ->orWhereNotNull('phone')
            ->orWhereNotNull('email')
            ->orderBy('id')
            ->eachById(function (Patient $patient): void {
                $patient->refreshIdentitySearch();
                $patient->saveQuietly();
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropIndex(['email_normalized']);
            $table->dropColumn('email_normalized');

            $table->dropIndex(['phone_normalized']);
            $table->dropColumn('phone_normalized');

            $table->dropIndex(['cnic_hash']);
            $table->dropColumn('cnic_hash');
        });
    }
};
