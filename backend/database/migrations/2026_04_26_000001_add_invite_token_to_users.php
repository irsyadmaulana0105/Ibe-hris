<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Store SHA-256 hash of the plain token, never plaintext
            $table->string('invite_token', 64)->nullable()->after('password');
            $table->timestamp('invite_token_expires_at')->nullable()->after('invite_token');
            $table->timestamp('invite_accepted_at')->nullable()->after('invite_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invite_token', 'invite_token_expires_at', 'invite_accepted_at']);
        });
    }
};
