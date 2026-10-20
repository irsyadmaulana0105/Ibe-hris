<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the Stalwart mailbox password encrypted (Laravel Crypt::encryptString)
 * so we can auto-login the user to webmail.ibereality.com via portal SSO
 * without prompting for a password.
 *
 * Used by GET /api/v1/auth/webmail-session which returns an HTML form that
 * auto-submits to the webmail login page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('mailbox_password_encrypted')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('mailbox_password_encrypted');
        });
    }
};
