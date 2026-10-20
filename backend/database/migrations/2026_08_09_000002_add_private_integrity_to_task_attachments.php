<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_attachments')) {
            throw new RuntimeException('task_attachments must exist before adding private-asset integrity metadata.');
        }

        if (! Schema::hasColumn('task_attachments', 'content_sha256')) {
            Schema::table('task_attachments', function (Blueprint $table): void {
                $table->char('content_sha256', 64)->nullable();
            });
        }
        if (! Schema::hasColumn('task_attachments', 'source_tombstoned_at')) {
            Schema::table('task_attachments', function (Blueprint $table): void {
                $table->timestamp('source_tombstoned_at')->nullable();
            });
        }
        if (! Schema::hasColumn('task_attachments', 'source_tombstone_reason')) {
            Schema::table('task_attachments', function (Blueprint $table): void {
                $table->string('source_tombstone_reason', 64)->nullable();
            });
        }
    }

    /**
     * Additive security metadata is intentionally retained during code rollback.
     */
    public function down(): void {}
};
