<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `inventory_batches.expiry_date` was created NOT NULL, but a batch can
     * legitimately have no expiry date on file: some supplies (equipment,
     * dressings, non-perishables) are not dated, and a supplier receipt may
     * omit the date. The column therefore has to accept NULL.
     *
     * This is additive and lossless. FEFO dispensing and the expiry scan
     * already treat a missing expiry as "not expiring"
     * (`ScanExpiringStockCommand` filters `whereNotNull('expiry_date')`), so
     * this aligns the schema with behaviour the pharmacy code already
     * implements rather than changing it.
     *
     * The expiry report (DESIGN §16 "pharmacy stock/expiry") surfaces undated
     * batches in their own `no_expiry_date` bucket instead of silently
     * omitting them, so a clinic can chase the missing paperwork.
     */
    public function up(): void
    {
        $this->rewriteNullable(true);
    }

    /**
     * Reverse the migrations.
     *
     * Not safely reversible while undated batches exist: restoring NOT NULL
     * cannot hold a NULL expiry, and silently dropping those batches would
     * destroy stock records (PRD §8 — the stock ledger must stay
     * authoritative). The rollback therefore refuses on such data, which is
     * the correct outcome: the clinic must date the batches or leave this
     * migration in place.
     *
     * The refusal is an explicit pre-check rather than a reliance on the
     * rebuilt table's NOT NULL constraint raising. Relying on the constraint
     * is engine-dependent and unsafe: SQLite and strict-mode MySQL both raise,
     * but MySQL under a non-strict `sql_mode` silently coerces NULL to
     * `'0000-00-00'` instead of failing. That outcome is worse than the one
     * this guard prevents — FEFO (`ScanExpiringStockCommand`, and dispensing
     * batch selection) reads `expiry_date` and would treat every undated batch
     * as expired since year zero, making undated stock undispensable and
     * poisoning the expiry report with fake expiries.
     *
     * The rebuild still needs `defer_foreign_keys` because
     * `inventory_batches` is referenced by `dispensings` and `stock_alerts`.
     */
    public function down(): void
    {
        $this->assertNoUndatedBatches();

        $this->rewriteNullable(false);
    }

    /**
     * Refuse to restore NOT NULL while any batch has no expiry date on file.
     *
     * Counted across soft-deleted rows too: a trashed batch is still a stock
     * record, and the rebuild copies it verbatim, so a soft-deleted undated
     * row would fail the rebuild in exactly the same way a live one does.
     */
    private function assertNoUndatedBatches(): void
    {
        $undated = DB::table('inventory_batches')
            ->whereNull('expiry_date')
            ->count();

        if ($undated === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to roll back the nullable-expiry migration: %d inventory batch(es) '
            .'have no expiry date and cannot be stored in a NOT NULL column. Date the '
            .'batches, or leave this migration applied. Dropping them would destroy stock '
            .'records, which PRD §8 forbids.',
            $undated,
        ));
    }

    /**
     * Recreate `inventory_batches` with the target nullability on `expiry_date`.
     *
     * SQLite cannot ALTER a column in place, so it needs the same
     * create/copy/drop/rename dance the expenses ENUM migration uses. MySQL
     * does it in a single statement. Written per driver rather than with
     * `->change()` because Laravel's SQLite `change()` rebuilds the table with
     * foreign keys enforced and fails on this schema.
     */
    private function rewriteNullable(bool $nullable): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // `inventory_batches` is referenced by `dispensings` and
            // `stock_alerts`. SQLite refuses to DROP a table that another table
            // still points at, and `PRAGMA foreign_keys = OFF` is a no-op
            // inside a transaction (which is how the test suite runs this).
            // `defer_foreign_keys` does take effect in a transaction: it moves
            // the enforcement to COMMIT, which is late enough for the rebuild.
            DB::statement('PRAGMA defer_foreign_keys = ON');

            $definition = $nullable ? 'DATE NULL' : 'DATE NOT NULL';

            DB::statement("CREATE TABLE inventory_batches_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
                batch_number VARCHAR(60) NOT NULL,
                expiry_date {$definition},
                initial_quantity NUMERIC(12,2) NOT NULL DEFAULT 0,
                current_quantity NUMERIC(12,2) NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                deleted_at DATETIME NULL
            )");

            DB::statement('INSERT INTO inventory_batches_new (id, inventory_item_id, batch_number, expiry_date, initial_quantity, current_quantity, created_at, updated_at, deleted_at)
                SELECT id, inventory_item_id, batch_number, expiry_date, initial_quantity, current_quantity, created_at, updated_at, deleted_at FROM inventory_batches');

            DB::statement('DROP TABLE inventory_batches');
            DB::statement('ALTER TABLE inventory_batches_new RENAME TO inventory_batches');
            DB::statement('CREATE INDEX inventory_batches_inventory_item_id_expiry_date_index ON inventory_batches (inventory_item_id, expiry_date)');
            DB::statement('CREATE INDEX inventory_batches_deleted_at_index ON inventory_batches (deleted_at)');

            DB::statement('PRAGMA defer_foreign_keys = OFF');

            return;
        }

        Schema::table('inventory_batches', function (Blueprint $table) use ($nullable): void {
            $column = $table->date('expiry_date');
            $nullable ? $column->nullable() : $column->nullable(false);
            $column->change();
        });
    }
};
