<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE api_idempotency_keys MODIFY user_id CHAR(26) NOT NULL');
    }

    public function down(): void
    {
        // Deliberately irreversible: converting ULIDs back to BIGINT is lossy.
    }
};
