<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $missingTables = array_values(array_filter(
            ['users', 'user_roles'],
            static fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missingTables !== []) {
            throw new LogicException(sprintf(
                'Cannot add the marketing role: required table(s) missing: %s.',
                implode(', ', $missingTables),
            ));
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite implements enum as a CHECK constraint. Rebuild this test
            // column as string; application validation remains authoritative.
            Schema::table('user_roles', function (Blueprint $table) {
                $table->string('role')->change();
            });

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new LogicException("Unsupported database driver for marketing role migration: {$driver}.");
        }

        DB::statement("ALTER TABLE users MODIFY `group` ENUM('user','admin','superadmin','finance','produksi','direktur','marketing') NOT NULL DEFAULT 'user'");
        DB::statement("ALTER TABLE user_roles MODIFY `role` ENUM('admin','superadmin','finance','produksi','direktur','marketing') NOT NULL");
    }

    public function down(): void
    {
        // Deliberately additive. A code rollback must not delete role grants or
        // rewrite existing user identities. Forward recovery preserves audit
        // provenance and avoids silently reducing access assignments.
    }
};
