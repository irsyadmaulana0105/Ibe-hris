<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time SSO tickets for webmail auto-login. Frontend exchanges its
 * Sanctum Bearer token for a short-lived ticket (60s, single-use) and
 * navigates the user to /auth/webmail-redirect?ticket=xxx (no header).
 * Backend redeems the ticket, performs OAuth2 password grant against
 * Stalwart's webmail, then returns an HTML page that hands the resulting
 * access token to the webmail client via localStorage + redirect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_sso_tickets', function (Blueprint $t) {
            $t->id();
            $t->string('ticket', 64)->unique();
            $t->char('user_id', 26);
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->dateTime('expires_at');
            $t->dateTime('used_at')->nullable();
            $t->timestamps();
            $t->index(['expires_at', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_sso_tickets');
    }
};
