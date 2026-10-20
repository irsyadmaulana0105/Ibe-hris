<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wipe any existing plaintext values before dropping the column
        DB::table('users')->whereNotNull('raw_password')->update(['raw_password' => null]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('raw_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('raw_password')->nullable()->after('password');
        });
    }
};
