<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-service email migration tokens. Admin broadcasts a link to each
 * karyawan's CURRENT (Gmail) email - they click, pick a username, and the
 * system provisions a {username}@ibereality.com Stalwart mailbox + updates
 * users.email so they can use the new credentials going forward.
 *
 * Token is single-use, 7-day TTL by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_migration_invites', function (Blueprint $t) {
            $t->id();
            $t->char('user_id', 26);
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->string('token', 64)->unique();
            $t->string('sent_to_email');           // snapshot of email at send time
            $t->dateTime('sent_at')->nullable();
            $t->dateTime('expires_at');
            $t->dateTime('claimed_at')->nullable();
            $t->string('chosen_username')->nullable();   // local-part chosen by user
            $t->string('new_email')->nullable();         // final username@ibereality.com
            $t->string('admin_id', 26)->nullable();      // who triggered the broadcast
            $t->timestamps();
            $t->index(['expires_at', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_migration_invites');
    }
};
