<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web Push subscriptions - satu device/browser instance per row.
 *
 * Saat user grant notification permission di PWA, browser issue subscription
 * dengan endpoint URL + p256dh/auth keys. Kita simpan + dipakai backend
 * untuk push payload (vendor: minishlink/web-push).
 *
 * Endpoint hash dipakai untuk dedup - kalau subscription sama register lagi,
 * upsert by hash instead of duplicate.
 *
 * last_active_at di-update tiap kali user buka webmail - sub yang stale
 * > 30 hari di-purge by cron (browser endpoints biasanya expire ~6 bulan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->char('user_id', 26);
            $t->string('endpoint_hash', 64)->unique()->comment('sha256(endpoint) - for dedup');
            $t->text('endpoint');
            $t->string('p256dh', 200);
            $t->string('auth', 100);
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('last_active_at')->nullable();
            $t->timestamp('last_pushed_at')->nullable();
            $t->unsignedInteger('push_failures')->default(0);
            $t->timestamps();

            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->index(['user_id', 'last_active_at']);
        });

        // Track JMAP "last seen email ID" per user - dipakai cron command
        // untuk deteksi mail baru tanpa harus query full inbox tiap kali.
        Schema::create('webmail_inbox_state', function (Blueprint $t) {
            $t->id();
            $t->char('user_id', 26)->unique();
            $t->string('last_seen_email_id', 100)->nullable();
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamps();

            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_inbox_state');
        Schema::dropIfExists('web_push_subscriptions');
    }
};
