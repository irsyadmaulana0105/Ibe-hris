<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_gateway_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->char('user_id', 26)->index();
            $table->string('mailbox_email');
            $table->dateTime('expires_at')->index();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('revoked_at')->nullable()->index();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_gateway_sessions');
    }
};
