<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TABLE expenses_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                branch_id INTEGER NULL REFERENCES branches(id) ON DELETE SET NULL,
                expense_category_id INTEGER NOT NULL REFERENCES expense_categories(id) ON DELETE CASCADE,
                expense_number VARCHAR(50) NOT NULL UNIQUE,
                expense_date DATE NOT NULL,
                vendor VARCHAR(150) NULL,
                amount NUMERIC(14,2) NOT NULL DEFAULT 0,
                tax NUMERIC(14,2) NOT NULL DEFAULT 0,
                total NUMERIC(14,2) NOT NULL DEFAULT 0,
                payment_status TEXT NOT NULL DEFAULT 'pending' CHECK (payment_status IN ('pending', 'paid', 'partial', 'rejected')),
                payment_method VARCHAR(30) NULL,
                description TEXT NULL,
                notes TEXT NULL,
                created_by INTEGER NOT NULL REFERENCES users(id),
                approved_by INTEGER NULL REFERENCES users(id),
                approved_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )");
            DB::statement('INSERT INTO expenses_new (id, branch_id, expense_category_id, expense_number, expense_date, vendor, amount, tax, total, payment_status, payment_method, description, notes, created_by, approved_by, approved_at, created_at, updated_at) SELECT id, branch_id, expense_category_id, expense_number, expense_date, vendor, amount, tax, total, payment_status, payment_method, description, notes, created_by, approved_by, approved_at, created_at, updated_at FROM expenses');
            DB::statement('DROP TABLE expenses');
            DB::statement('ALTER TABLE expenses_new RENAME TO expenses');
            DB::statement('CREATE INDEX expenses_branch_id_expense_date_index ON expenses (branch_id, expense_date)');
            DB::statement('CREATE INDEX expenses_expense_category_id_expense_date_index ON expenses (expense_category_id, expense_date)');
            DB::statement('CREATE INDEX expenses_payment_status_index ON expenses (payment_status)');
        } else {
            DB::statement("ALTER TABLE expenses MODIFY payment_status ENUM('pending', 'paid', 'partial', 'rejected') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TABLE expenses_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                branch_id INTEGER NULL REFERENCES branches(id) ON DELETE SET NULL,
                expense_category_id INTEGER NOT NULL REFERENCES expense_categories(id) ON DELETE CASCADE,
                expense_number VARCHAR(50) NOT NULL UNIQUE,
                expense_date DATE NOT NULL,
                vendor VARCHAR(150) NULL,
                amount NUMERIC(14,2) NOT NULL DEFAULT 0,
                tax NUMERIC(14,2) NOT NULL DEFAULT 0,
                total NUMERIC(14,2) NOT NULL DEFAULT 0,
                payment_status TEXT NOT NULL DEFAULT 'pending' CHECK (payment_status IN ('pending', 'paid', 'partial')),
                payment_method VARCHAR(30) NULL,
                description TEXT NULL,
                notes TEXT NULL,
                created_by INTEGER NOT NULL REFERENCES users(id),
                approved_by INTEGER NULL REFERENCES users(id),
                approved_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )");
            DB::statement('INSERT INTO expenses_new (id, branch_id, expense_category_id, expense_number, expense_date, vendor, amount, tax, total, payment_status, payment_method, description, notes, created_by, approved_by, approved_at, created_at, updated_at) SELECT id, branch_id, expense_category_id, expense_number, expense_date, vendor, amount, tax, total, payment_status, payment_method, description, notes, created_by, approved_by, approved_at, created_at, updated_at FROM expenses');
            DB::statement('DROP TABLE expenses');
            DB::statement('ALTER TABLE expenses_new RENAME TO expenses');
            DB::statement('CREATE INDEX expenses_branch_id_expense_date_index ON expenses (branch_id, expense_date)');
            DB::statement('CREATE INDEX expenses_expense_category_id_expense_date_index ON expenses (expense_category_id, expense_date)');
            DB::statement('CREATE INDEX expenses_payment_status_index ON expenses (payment_status)');
        } else {
            DB::statement("ALTER TABLE expenses MODIFY payment_status ENUM('pending', 'paid', 'partial') NOT NULL DEFAULT 'pending'");
        }
    }
};
