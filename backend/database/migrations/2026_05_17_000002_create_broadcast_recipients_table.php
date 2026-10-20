<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user delivery record untuk broadcast. Dipakai inbox karyawan
 * (unread badge + history) dan untuk audit "siapa udah baca apa".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_recipients', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('broadcast_id')->index();
            $t->ulid('user_id')->index();
            $t->timestamp('email_sent_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->unique(['broadcast_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
    }
};
