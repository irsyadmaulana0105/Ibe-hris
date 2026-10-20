<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('office_locations', 'created_by')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // created_by was bigint but users use ULID (char 26)
        DB::statement('ALTER TABLE office_locations MODIFY COLUMN created_by char(26) NULL');
    }

    public function down(): void
    {
        if (!Schema::hasColumn('office_locations', 'created_by')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE office_locations MODIFY COLUMN created_by bigint unsigned NULL');
    }
};
