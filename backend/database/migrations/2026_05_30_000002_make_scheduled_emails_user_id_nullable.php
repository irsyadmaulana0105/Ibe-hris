<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Untuk dukung scheduled email dari shared mailbox (mis. admin@ibereality.com)
 * yang BUKAN user record. user_id null = jadwal dibuat dari shared mailbox,
 * routing pakai mailbox_username saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_emails', function (Blueprint $t) {
            $t->ulid('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_emails', function (Blueprint $t) {
            $t->ulid('user_id')->nullable(false)->change();
        });
    }
};
