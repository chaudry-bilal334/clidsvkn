<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finalization + immutability guards (PRD §7, §9, §10, §11, §17).
 *
 * 1. Finance — `invoices` / `payments` / `refunds` gain the finalization and
 *    linked-reversal columns the PRD requires, and every FK along the
 *    charge -> payment -> refund -> claim chain switches from CASCADE to
 *    RESTRICT so a single DELETE cannot erase a settled financial audit trail.
 * 2. Lab — `lab_orders` gains `finalized_at` / `finalized_by`, matching the
 *    treatment already given to encounters and prescriptions.
 * 3. Audit — `audit_logs.user_id` switches from CASCADE to SET NULL: removing
 *    a user must never remove the record of what they did. Same for the
 *    clinical safety acknowledgements in `clinical_decision_supports`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dateTime('finalized_at')->nullable()->after('issued_at');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of')->nullable()->after('finalized_by')->constrained('invoices')->nullOnDelete();
            $table->dateTime('voided_at')->nullable()->after('reversal_of');
            $table->text('void_reason')->nullable()->after('voided_at');

            $table->index(['status', 'finalized_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('status', 20)->default('recorded')->after('method');
            $table->dateTime('finalized_at')->nullable()->after('paid_at');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of')->nullable()->after('finalized_by')->constrained('payments')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->after('reversal_of')->constrained('users')->nullOnDelete();
            $table->string('txn_ref', 120)->nullable()->after('received_by');
            $table->foreignId('branch_id')->nullable()->after('txn_ref')->constrained()->nullOnDelete();

            $table->index(['invoice_id', 'status']);
            // Gateway replay protection: one gateway reference settles once.
            $table->unique(['method', 'txn_ref']);
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->string('refund_number', 40)->nullable()->after('id');
            $table->string('status', 20)->default('completed')->after('amount');
            $table->foreignId('refund_of')->nullable()->after('status')->constrained('refunds')->nullOnDelete();

            $table->index('refund_of');
            $table->unique('refund_number');
        });

        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dateTime('finalized_at')->nullable()->after('verified_at');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();

            $table->index(['verification_state', 'finalized_at']);
        });

        // A settled charge chain must never cascade away.
        $this->reforeignRestricting('invoice_items', 'invoice_id', 'invoices');
        $this->reforeignRestricting('payments', 'invoice_id', 'invoices');
        $this->reforeignRestricting('refunds', 'invoice_id', 'invoices');
        $this->reforeignRestricting('claims', 'invoice_id', 'invoices');
        $this->reforeignRestricting('jazzcash_transactions', 'invoice_id', 'invoices');

        // Audit trails survive the actor's account. `doctor_id` is currently
        // NOT NULL, so the column is relaxed in the same table rebuild that
        // re-points the key.
        Schema::table('clinical_decision_supports', function (Blueprint $table) {
            $table->dropForeign(['doctor_id']);
            $table->foreignId('doctor_id')->nullable()->change();
            $table->foreign('doctor_id')->references('id')->on('users')->nullOnDelete();
        });

        $this->reforeignNulling('audit_logs', 'user_id', 'users');
    }

    public function down(): void
    {
        Schema::table('clinical_decision_supports', function (Blueprint $table) {
            $table->dropForeign(['doctor_id']);
            $table->foreignId('doctor_id')->nullable(false)->change();
            $table->foreign('doctor_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $this->reforeignCascading('audit_logs', 'user_id', 'users');
        $this->reforeignCascading('jazzcash_transactions', 'invoice_id', 'invoices');
        $this->reforeignCascading('claims', 'invoice_id', 'invoices');
        $this->reforeignCascading('refunds', 'invoice_id', 'invoices');
        $this->reforeignCascading('payments', 'invoice_id', 'invoices');
        $this->reforeignCascading('invoice_items', 'invoice_id', 'invoices');

        Schema::table('clinical_decision_supports', function (Blueprint $table) {
            $table->foreignId('doctor_id')->nullable(false)->change();
        });

        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dropIndex(['verification_state', 'finalized_at']);
            $table->dropForeign(['finalized_by']);
            $table->dropColumn(['finalized_at', 'finalized_by']);
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique(['refund_number']);
            $table->dropIndex(['refund_of']);
            $table->dropForeign(['refund_of']);
            $table->dropColumn(['refund_number', 'status', 'refund_of']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['method', 'txn_ref']);
            $table->dropIndex(['invoice_id', 'status']);
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['received_by']);
            $table->dropForeign(['reversal_of']);
            $table->dropForeign(['finalized_by']);
            $table->dropColumn([
                'status', 'finalized_at', 'finalized_by',
                'reversal_of', 'received_by', 'txn_ref', 'branch_id',
            ]);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'finalized_at']);
            $table->dropForeign(['finalized_by']);
            $table->dropForeign(['reversal_of']);
            $table->dropColumn(['finalized_at', 'finalized_by', 'reversal_of', 'voided_at', 'void_reason']);
        });
    }

    /**
     * Point an existing foreign key at a new delete rule.
     *
     * Laravel 13 rebuilds the table natively for SQLite and issues
     * DROP/ADD FOREIGN KEY for MySQL, so the same code covers dev and the
     * MySQL production baseline (PRD §11).
     */
    private function reforeign(string $table, string $column, string $on, string $rule): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column, $on, $rule): void {
            $blueprint->dropForeign([$column]);

            $foreign = $blueprint->foreign($column)->references('id')->on($on);

            match ($rule) {
                'RESTRICT' => $foreign->restrictOnDelete(),
                'SET NULL' => $foreign->nullOnDelete(),
                default => $foreign->cascadeOnDelete(),
            };
        });
    }

    private function reforeignRestricting(string $table, string $column, string $on): void
    {
        $this->reforeign($table, $column, $on, 'RESTRICT');
    }

    private function reforeignNulling(string $table, string $column, string $on): void
    {
        $this->reforeign($table, $column, $on, 'SET NULL');
    }

    private function reforeignCascading(string $table, string $column, string $on): void
    {
        $this->reforeign($table, $column, $on, 'CASCADE');
    }
};
