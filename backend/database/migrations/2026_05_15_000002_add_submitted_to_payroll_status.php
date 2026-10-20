<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend payroll status enum: draft → submitted → approved → paid.
 *
 * HR generates + submits, Finance approves + transfers (marks paid).
 * Superadmin can bypass any transition.
 *
 * Existing MariaDB/MySQL installations need a raw ALTER. Fresh SQLite test
 * databases receive the complete CHECK constraint from the original create
 * migration, which already includes `submitted`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE payrolls MODIFY COLUMN status ENUM('draft','submitted','approved','paid') NOT NULL DEFAULT 'draft'");
        }
        // Fresh SQLite/pgsql schemas already include the value in the create
        // migration. This migration remains the production upgrade path.
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // Demote any 'submitted' rows back to 'draft' before shrinking enum
        DB::table('payrolls')->where('status', 'submitted')->update(['status' => 'draft']);

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE payrolls MODIFY COLUMN status ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft'");
        }
    }
};
