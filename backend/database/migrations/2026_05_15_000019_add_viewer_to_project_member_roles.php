<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite stores Laravel enums as text, so the original column already
        // accepts the new value. Keep the migration portable for API tests.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // MySQL: extend the enum to include 'viewer'. We do this via raw SQL
        // because Laravel's Schema builder doesn't support enum-modify natively.
        DB::statement("
            ALTER TABLE project_members
            MODIFY COLUMN role ENUM('member', 'lead', 'owner', 'viewer') NOT NULL DEFAULT 'member'
        ");
    }

    public function down(): void
    {
        // Promote any 'viewer' to 'member' before reverting (data safety)
        DB::table('project_members')->where('role', 'viewer')->update(['role' => 'member']);
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("
            ALTER TABLE project_members
            MODIFY COLUMN role ENUM('member', 'lead', 'owner') NOT NULL DEFAULT 'member'
        ");
    }
};
