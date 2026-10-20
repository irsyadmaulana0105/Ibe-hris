<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah value 'cancelled' ke enum leaves.status.
 * Sebelumnya: pending | approved | rejected.
 * Dibutuhkan untuk fitur batalkan cuti yang sudah approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL/MariaDB: ALTER ENUM in place (preserves existing data)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE leaves MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        // Revert any cancelled back to rejected before shrinking enum (avoid truncation)
        DB::table('leaves')->where('status', 'cancelled')->update(['status' => 'rejected']);
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE leaves MODIFY COLUMN status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'");
        }
    }
};
