<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE user_roles MODIFY `role` ENUM('admin','superadmin','finance','produksi','direktur') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE user_roles MODIFY `role` ENUM('admin','superadmin','finance','produksi') NOT NULL");
        }
    }
};
