<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the index before mutating the column (MySQL requires this for
        // some storage engines and avoids subtle "index uses obsolete type"
        // surprises later).
        Schema::table('task_attachments', function (Blueprint $table) {
            $table->dropIndex(['storage_provider']);
        });

        // Switch from ENUM('local','google_drive') to a plain VARCHAR(20) so we
        // can add 'r2' (and future providers) without another DDL change.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('task_attachments', function (Blueprint $table) {
                $table->string('storage_provider', 20)->default('local')->change();
            });
        } else {
            DB::statement("ALTER TABLE task_attachments MODIFY storage_provider VARCHAR(20) NOT NULL DEFAULT 'local'");
        }

        Schema::table('task_attachments', function (Blueprint $table) {
            $table->index(['storage_provider']);
        });
    }

    public function down(): void
    {
        Schema::table('task_attachments', function (Blueprint $table) {
            $table->dropIndex(['storage_provider']);
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('task_attachments', function (Blueprint $table) {
                $table->string('storage_provider', 20)->default('local')->change();
            });
        } else {
            DB::statement("ALTER TABLE task_attachments MODIFY storage_provider ENUM('local','google_drive') NOT NULL DEFAULT 'local'");
        }

        Schema::table('task_attachments', function (Blueprint $table) {
            $table->index(['storage_provider']);
        });
    }
};
