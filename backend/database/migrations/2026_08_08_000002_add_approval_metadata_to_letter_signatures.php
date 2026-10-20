<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('letter_signatures', 'prepared_by_id')) {
            Schema::table('letter_signatures', function (Blueprint $table): void {
                $table->foreignUlid('prepared_by_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('letter_signatures', 'approved_by_id')) {
            Schema::table('letter_signatures', function (Blueprint $table): void {
                $table->foreignUlid('approved_by_id')
                    ->nullable()
                    ->after('prepared_by_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('letter_signatures', 'approved_at')) {
            Schema::table('letter_signatures', function (Blueprint $table): void {
                $table->timestamp('approved_at')->nullable()->after('approved_by_id');
            });
        }
    }

    public function down(): void
    {
        // Intentionally preserve approval provenance on code rollback. The up()
        // checks make a later re-apply idempotent even when these columns remain.
    }
};
