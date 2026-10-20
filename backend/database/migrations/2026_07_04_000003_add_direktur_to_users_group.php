<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * users.group ENUM sebelumnya cuma: user, admin, superadmin, finance, produksi.
 * Tambah 'direktur' untuk CEO/Direktur Utama.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY `group` ENUM('user','admin','superadmin','finance','produksi','direktur') NOT NULL DEFAULT 'user'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY `group` ENUM('user','admin','superadmin','finance','produksi') NOT NULL DEFAULT 'user'");
        }
    }
};
