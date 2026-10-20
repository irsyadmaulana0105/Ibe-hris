<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tambah role 'produksi' ke users.group enum. Role ini untuk tim produksi
 * yang menangani Serial Number Produk Robot + Customer Complaint.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE users MODIFY COLUMN `group` ENUM('user','admin','superadmin','finance','produksi') NOT NULL DEFAULT 'user'");
        }
        // sqlite (testing): groups checked via Model::$groups, no enum constraint
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE users MODIFY COLUMN `group` ENUM('user','admin','superadmin','finance') NOT NULL DEFAULT 'user'");
        }
    }
};
